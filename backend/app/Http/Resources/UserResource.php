<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ─────────────────────────────────────────────────────────────────────────────
// UserResource
// ─────────────────────────────────────────────────────────────────────────────
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'first_name'      => $this->first_name,
            'last_name'       => $this->last_name,
            'full_name'       => $this->full_name,
            'email'           => $this->email,
            'role'            => $this->role,
            'avatar_url'      => $this->avatar_url,
            'github_username' => $this->github_username,
            'is_active'       => $this->is_active,
            'is_verified'     => $this->is_verified,
            'last_login'      => $this->last_login?->toIso8601String(),
            'created_at'      => $this->created_at->toIso8601String(),
        ];
    }
}
