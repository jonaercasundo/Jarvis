<?php

namespace App\Models;

use Database\Factories\ApiConnectionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiConnection extends Model
{
    /** @use HasFactory<ApiConnectionFactory> */
    use HasFactory;

    protected $fillable = ['owner_key', 'name', 'base_url', 'authentication', 'token'];

    protected $hidden = ['owner_key', 'token'];

    protected $appends = ['integration'];

    protected function integration(): Attribute
    {
        return Attribute::make(get: fn (): string => match (true) {
            str_contains(strtolower($this->name ?? ''), 'metabase'),
            str_contains(strtolower(parse_url($this->base_url ?? '', PHP_URL_HOST) ?: ''), 'metabase'),
            $this->authentication === 'metabase_api_key',
            $this->authentication === 'metabase_session' => 'metabase',
            str_contains(strtolower($this->name ?? ''), 'mmc tracker'),
            parse_url($this->base_url ?? '', PHP_URL_HOST) === 'tracker.metro-mobilia.com' => 'mmc_tracker',
            parse_url($this->base_url ?? '', PHP_URL_HOST) === 'graph.microsoft.com' => 'outlook',
            default => 'generic',
        });
    }

    /** @return array<string, string> */
    public function authenticationHeaders(): array
    {
        if ($this->authentication === 'none') {
            return [];
        }
        if (! $this->token) {
            throw new \RuntimeException('The saved connection needs an authentication token.');
        }

        return match ($this->authentication) {
            'bearer' => ['Authorization' => 'Bearer '.$this->token],
            'metabase_api_key' => ['X-API-Key' => $this->token],
            'metabase_session' => ['X-Metabase-Session' => $this->token],
            default => throw new \RuntimeException('Unsupported saved connection authentication.'),
        };
    }

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }
}
