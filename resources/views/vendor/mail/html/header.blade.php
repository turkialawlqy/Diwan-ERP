@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<span style="display: inline-block; background: #4f46e5; color: #ffffff; border-radius: 12px; padding: 8px 14px; font-size: 20px; font-weight: 800;">{!! $slot !!}</span>
<span style="display: block; color: #64748b; font-size: 12px; margin-top: 6px;">{{ config('app.brand.subtitle') }}</span>
</a>
</td>
</tr>
