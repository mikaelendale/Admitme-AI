<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Models\KnowledgeSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Operator-only: record an institution's verified website domain. Pages on it then count as official,
 * shared knowledge. Students cannot do this from the app — their "this is the official site" stays a
 * private, unverified source (D-016).
 */
class SetInstitutionDomainCommand extends Command
{
    protected $signature = 'admitme:institution-domain
        {institution : Institution slug or exact name}
        {domain? : Verified domain, e.g. admissions.lakeside.edu or lakeside.edu}
        {--name= : Create the institution with this name if it does not exist yet}
        {--clear : Remove the verified domain}';

    protected $description = 'Show or set the verified official website domain for an institution';

    public function handle(): int
    {
        $key = trim((string) $this->argument('institution'));
        $institution = Institution::query()->where('slug', $key)->orWhere('name', $key)->first();

        if ($institution === null && is_string($this->option('name')) && trim($this->option('name')) !== '') {
            $institution = Institution::query()->create(['slug' => Str::slug($key), 'name' => trim($this->option('name'))]);
        }

        if ($institution === null) {
            $this->error("No institution matches \"{$key}\". It is created when a student first opens that college's requirements, or pass --name to create it now.");

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $institution->update(['website_domain' => null]);
            Log::info('Institution domain cleared', ['institution_id' => $institution->id]);
            $this->info("Cleared the verified domain for {$institution->name}. Existing official sources keep their label until re-added.");

            return self::SUCCESS;
        }

        $domain = $this->argument('domain');

        if (! is_string($domain) || trim($domain) === '') {
            $this->line("{$institution->name} ({$institution->slug}): ".($institution->website_domain ?? 'no verified domain'));

            return self::SUCCESS;
        }

        $domain = strtolower(trim((string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($domain)), '/'));
        $domain = explode('/', $domain)[0];

        if (! str_contains($domain, '.') || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            $this->error("\"{$domain}\" is not a valid domain.");

            return self::FAILURE;
        }

        $before = $institution->website_domain;
        $institution->update(['website_domain' => $domain]);
        Log::info('Institution domain verified', ['institution_id' => $institution->id, 'before' => $before, 'after' => $domain]);

        $privateOnDomain = KnowledgeSource::query()
            ->where('institution_id', $institution->id)
            ->whereNotNull('owner_user_id')
            ->get()
            ->filter(fn (KnowledgeSource $s) => $s->host === $domain || str_ends_with($s->host, '.'.$domain))
            ->count();

        $this->info("{$institution->name}: pages on {$domain} now count as official sources.");

        if ($privateOnDomain > 0) {
            $this->line("{$privateOnDomain} student-provided page(s) on this domain stay private; adding them again files them as official.");
        }

        return self::SUCCESS;
    }
}
