<?php

namespace App\Traits;

use App\Models\Scopes\TenantScope;

/**
 * Scopes a model to the current tenant and stamps `tenant_id` on create.
 *
 * Mirrors the inline `booted()` pattern used elsewhere, but factored out for
 * models (WhatsApp conversations/messages) that are written from a public
 * webhook as well as from the authenticated app: the webhook sets the tenant on
 * the request attributes, the app resolves it from the authenticated user.
 */
trait HasTenant
{
    protected static function bootHasTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->tenant_id) && auth()->check()) {
                $model->tenant_id = auth()->user()->tenant_id;
            }

            if (empty($model->tenant_id) && request() && request()->attributes->get('tenant')) {
                $model->tenant_id = request()->attributes->get('tenant')->id;
            }
        });
    }
}
