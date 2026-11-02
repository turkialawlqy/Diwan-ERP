<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The queue workers the installers set up serve every queue the jobs are sent to. A worker
 * started without --queue serves only "default": tax-authority submissions (zatca), webhooks
 * and data exports then wait in the jobs table for good.
 */
class QueueWorkersTest extends TestCase
{
    /** One worker for every queue: the office installers, the updaters that set up their older workers again, the dev script. */
    private const ONE_WORKER = [
        'deploy/mac/install.sh',
        'deploy/mac/update.sh',
        'install-mac-new.sh',
        'deploy/linux/install.sh',
        'deploy/linux/update.sh',
        'deploy/windows/install.ps1',
        'deploy/windows/local-update.ps1',
        'deploy/windows/install-auto-update.ps1',
        'composer.json',
    ];

    /** Servers: one supervisor program per group of queues. */
    private const SEVERAL_WORKERS = ['deploy/supervisor.conf', 'deploy/ubuntu/supervisor.conf'];

    public function test_the_queues_of_the_jobs_are_found(): void
    {
        $this->assertSame([], array_values(array_diff(['zatca', 'default', 'webhooks', 'exports'], $this->queuesUsed())));
    }

    public function test_each_office_worker_serves_every_queue(): void
    {
        $queues = $this->queuesUsed();
        foreach (self::ONE_WORKER as $file) {
            $lists = $this->queueLists($file);
            $this->assertNotEmpty($lists, "{$file} names no queues: a worker without --queue serves only \"default\".");
            foreach ($lists as $list) {
                $this->assertSame([], array_values(array_diff($queues, $list)), "{$file}: --queue=".implode(',', $list).' leaves out these queues.');
            }
        }
    }

    public function test_the_server_workers_together_serve_every_queue(): void
    {
        $queues = $this->queuesUsed();
        foreach (self::SEVERAL_WORKERS as $file) {
            $served = array_merge(...$this->queueLists($file));
            $this->assertSame([], array_values(array_diff($queues, $served)), "No worker in {$file} serves these queues.");
        }
    }

    /** "default" (where a job that names no queue goes) and every queue the code names. */
    private function queuesUsed(): array
    {
        $queues = ['default'];
        foreach (Finder::create()->files()->in([app_path(), base_path('routes')])->name('*.php') as $file) {
            preg_match_all('/\b(?:onQueue|queueOn|pushOn|laterOn)\(\s*[\'"]([\w.-]+)[\'"]|(?:public|protected)\s+(?:\??string\s+)?\$queue\s*=\s*[\'"]([\w.-]+)[\'"]/', $file->getContents(), $m);
            $queues = array_merge($queues, array_filter($m[1]), array_filter($m[2]));
        }

        return array_values(array_unique($queues));
    }

    /** @return list<list<string>> the queues of each --queue= in the file */
    private function queueLists(string $file): array
    {
        preg_match_all('/--queue=([\w,-]+)/', file_get_contents(base_path($file)), $m);

        return array_map(fn ($list) => explode(',', $list), $m[1]);
    }
}
