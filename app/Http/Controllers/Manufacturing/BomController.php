<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Routing;
use App\Models\SubcontractOrder;
use App\Support\PickerOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BomController extends Controller
{
    public function index(): Response
    {
        $boms = Bom::with(['product:id,name_ar,sku', 'items.component:id,name_ar,average_cost'])->orderBy('product_id')->orderByDesc('is_default')->get()
            ->map(fn (Bom $b) => [
                'id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'version' => $b->version, 'is_default' => $b->is_default, 'is_active' => $b->is_active,
                'product' => $b->product?->name_ar, 'items_count' => $b->items->count(), 'output_quantity' => (float) $b->output_quantity,
                'unit_cost' => $this->rollUp($b),
            ]);

        return Inertia::render('Manufacturing/Boms/Index', ['boms' => $boms]);
    }

    /** Material cost of one unit at the components' average cost, with scrap allowances. */
    protected function rollUp(Bom $bom): float
    {
        $sum = $bom->items->sum(fn ($i) => (float) $i->quantity * (1 + (float) $i->scrap_percent / 100) * (float) ($i->component?->average_cost ?? 0));

        return round($sum * (1 + (float) $bom->scrap_percent / 100) / max(0.0001, (float) $bom->output_quantity), 4);
    }

    public function create(): Response
    {
        return $this->form(new Bom(['output_quantity' => 1, 'scrap_percent' => 0, 'version' => '1', 'is_default' => true, 'is_active' => true]));
    }

    public function edit(Bom $bom): Response
    {
        return $this->form($bom->load('items'));
    }

    protected function form(Bom $bom): Response
    {
        return Inertia::render('Manufacturing/Boms/Form', [
            'bom' => $bom,
            'products' => PickerOptions::from(Product::where('is_active', true)->where('type', '!=', 'service')->orderBy('name_ar'), ['id', 'name_ar', 'sku', 'average_cost'], [$bom->product_id, ...$bom->items->pluck('component_id')->all()]),
            'routings' => Routing::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $bom = DB::transaction(fn () => $this->save(new Bom, $request));

        return redirect()->route('manufacturing.boms.index')->with('success', "تم حفظ قائمة المواد «{$bom->name}».");
    }

    public function update(Request $request, Bom $bom)
    {
        DB::transaction(fn () => $this->save($bom, $request));

        return redirect()->route('manufacturing.boms.index')->with('success', 'تم تحديث قائمة المواد.');
    }

    public function destroy(Bom $bom)
    {
        if (ProductionOrder::where('bom_id', $bom->id)->exists() || SubcontractOrder::where('bom_id', $bom->id)->exists() || DB::table('assembly_orders')->where('bom_id', $bom->id)->exists()) {
            $bom->update(['is_active' => false, 'is_default' => false]);

            return back()->with('success', 'القائمة مستعملة في أوامر فأُوقفت ولم تُحذف.');
        }
        $bom->items()->delete();
        $bom->delete();

        return back()->with('success', 'تم حذف قائمة المواد.');
    }

    protected function save(Bom $bom, Request $request): Bom
    {
        $data = $request->validate([
            'product_id' => 'required|tenant_exists:products,id',
            'code' => 'nullable|string|max:30',
            'name' => 'required|string|max:255',
            'version' => 'required|string|max:20',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'output_quantity' => 'required|numeric|min:0.0001',
            'scrap_percent' => 'required|numeric|min:0|max:100',
            'routing_id' => 'nullable|tenant_exists:routings,id',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:200',
            'items.*.component_id' => 'required|tenant_exists:products,id|different:product_id',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.scrap_percent' => 'nullable|numeric|min:0|max:100',
        ]);
        $this->guardCycle((int) $data['product_id'], collect($data['items'])->pluck('component_id')->map(fn ($i) => (int) $i)->all(), $bom->id);

        $bom->fill(collect($data)->except('items')->all() + ['is_default' => $request->boolean('is_default'), 'is_active' => $request->boolean('is_active', true)])->save();
        if ($bom->is_default) {
            Bom::where('product_id', $bom->product_id)->whereKeyNot($bom->id)->update(['is_default' => false]);
        }
        $bom->items()->delete();
        foreach ($data['items'] as $item) {
            $bom->items()->create(['component_id' => $item['component_id'], 'quantity' => $item['quantity'], 'scrap_percent' => $item['scrap_percent'] ?? 0]);
        }

        return $bom;
    }

    /** A product cannot end up among its own components, however deep. */
    protected function guardCycle(int $productId, array $componentIds, ?int $ignoreBomId): void
    {
        $seen = [];
        $stack = $componentIds;
        while ($stack) {
            $id = array_pop($stack);
            if ($id === $productId) {
                throw ValidationException::withMessages(['items' => 'القائمة تحتوي المنتج نفسه ضمن مكوناتها (حلقة مغلقة).']);
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach (Bom::with('items')->where('product_id', $id)->where('is_active', true)->when($ignoreBomId, fn ($q) => $q->whereKeyNot($ignoreBomId))->get() as $child) {
                array_push($stack, ...$child->items->pluck('component_id')->all());
            }
        }
    }
}
