<?php

declare(strict_types=1);

namespace Nvl\Billing\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Billing\Services\BillingDoctor;

/**
 * Renders the package-owned read-only installation diagnostics.
 */
final class BillingDoctorCommand extends Command
{
    protected $signature = 'nvl:billing:doctor {--strict : Fail when Billing is enabled but incomplete} {--format=text : Output format: text or json}';

    protected $description = 'Inspect Stripe billing configuration and schema readiness';

    /** Report bounded configuration and schema checks without exposing secrets. */
    public function handle(BillingDoctor $doctor): int
    {

        $format = $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('Output format must be text or json.');

            return self::FAILURE;
        }

        $result = $doctor->inspect();
        $checks = $result['checks'];
        $healthy = $result['healthy'];

        if ($format === 'json') {
            $this->line(json_encode(['healthy' => $healthy, 'checks' => $checks], JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $passed) {
                $this->line(sprintf('[%s] %s', $passed ? 'PASS' : 'FAIL', $name));
            }
        }

        return $healthy || ! $this->option('strict') ? self::SUCCESS : self::FAILURE;
    }
}
