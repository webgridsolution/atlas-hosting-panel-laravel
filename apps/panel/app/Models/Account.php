<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'user_id', 'reseller_id', 'package_id', 'username', 'domain', 'status',
        'disk_mb', 'inodes', 'bandwidth_mb', 'php_version', 'notes', 'provisioned_at',
        'ssh_enabled', 'ssh_enabled_at',
    ];

    protected function casts(): array
    {
        return [
            'disk_mb' => 'integer',
            'inodes' => 'integer',
            'bandwidth_mb' => 'integer',
            'suspended_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'ssh_enabled' => 'boolean',
            'ssh_enabled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(HostingDatabase::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function dnsZones(): HasMany
    {
        return $this->hasMany(DnsZone::class);
    }

    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    public function databaseUsers(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }

    public function ftpAccounts(): HasMany
    {
        return $this->hasMany(FtpAccount::class);
    }

    public function forwarders(): HasMany
    {
        return $this->hasMany(EmailForwarder::class);
    }

    public function autoresponders(): HasMany
    {
        return $this->hasMany(Autoresponder::class);
    }

    public function emailFilters(): HasMany
    {
        return $this->hasMany(EmailFilter::class);
    }

    public function mailingLists(): HasMany
    {
        return $this->hasMany(MailingList::class);
    }

    public function ipBlocks(): HasMany
    {
        return $this->hasMany(IpBlock::class);
    }

    public function directoryProtections(): HasMany
    {
        return $this->hasMany(DirectoryProtection::class);
    }

    public function mimeTypes(): HasMany
    {
        return $this->hasMany(MimeType::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(SslCertificate::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(GitRepository::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(InstalledApplication::class);
    }

    public function bandwidthDays(): HasMany
    {
        return $this->hasMany(BandwidthDay::class);
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(Redirect::class);
    }

    public function remoteMysqlHosts(): HasMany
    {
        return $this->hasMany(RemoteMysqlHost::class);
    }

    /** Websites proper: primary, addon and subdomain — aliases serve another site. */
    public function sites(): HasMany
    {
        return $this->hasMany(Domain::class)
            ->whereIn('type', [Domain::TYPE_PRIMARY, Domain::TYPE_ADDON, Domain::TYPE_SUBDOMAIN]);
    }

    public function primaryDomain(): ?Domain
    {
        return $this->domains->firstWhere('is_primary', true) ?? $this->domains->first();
    }

    public function home(): string
    {
        return config('atlas.defaults.home').'/'.$this->username;
    }

    public function docRoot(): string
    {
        return $this->home().'/public_html';
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Accounts visible to a user: an admin sees everything, a reseller sees
     * their own accounts, a customer sees the account their login belongs to.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        if ($user->hasRole('reseller')) {
            return $query->where('reseller_id', $user->id);
        }

        return $query->where('user_id', $user->id);
    }
}
