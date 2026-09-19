<?php

namespace App\Http\Controllers\Cloud;

use App\Http\Controllers\Controller;
use App\Models\UserCloudConnection;
use App\Services\Cloud\CloudProviderFactory;
use Illuminate\Http\Request;

class CloudConnectionController extends Controller
{
    public function start(Request $request, CloudProviderFactory $factory, string $provider)
    {
        if (! in_array($provider, UserCloudConnection::PROVIDERS, true)) {
            abort(404);
        }

        $scope = $request->query('scope', 'calendar');
        if (! in_array($scope, UserCloudConnection::SCOPES, true)) {
            abort(422);
        }

        $redirect = (string) $request->query('redirect', route('my-cloud.show'));
        if (! str_starts_with($redirect, '/') || str_starts_with($redirect, '//') || str_starts_with($redirect, '/\\')) {
            $redirect = route('my-cloud.show');
        }

        try {
            $providerInstance = $factory->forStart($provider, auth()->user(), $scope);
        } catch (\RuntimeException $e) {
            return redirect()->to($redirect)->with('error', $e->getMessage());
        }

        $state = bin2hex(random_bytes(32));
        session()->put("cloud_oauth.{$state}", [
            'user_id' => auth()->id(),
            'provider' => $provider,
            'scope' => $scope,
            'redirect' => $redirect,
        ]);

        return redirect()->away($providerInstance->authorizeUrl($state, $scope));
    }

    public function callback(Request $request, CloudProviderFactory $factory, string $provider)
    {
        if (! in_array($provider, UserCloudConnection::PROVIDERS, true)) {
            abort(404);
        }

        $state = (string) $request->query('state', '');
        $payload = session()->pull("cloud_oauth.{$state}");

        if ($payload && $payload['provider'] === $provider && (int) $payload['user_id'] === auth()->id()) {
            $code = $request->query('code');
            $error = $request->query('error');

            if ($code) {
                try {
                    $connection = UserCloudConnection::firstOrNew(
                        ['user_id' => auth()->id(), 'provider' => $provider, 'scope' => $payload['scope']],
                        ['tenant_id' => auth()->user()->tenant_id]
                    );

                    $providerInstance = $factory->make($provider, $connection);
                    $result = $providerInstance->exchangeCode($code);

                    $connection->provider_account_email = $result['email'] ?? $connection->provider_account_email;
                    $connection->save();

                    return redirect()->to($payload['redirect'])->with('success', __('Your :provider account is connected.', [
                        'provider' => ucfirst($provider),
                    ]));
                } catch (\Throwable $e) {
                    return redirect()->to($payload['redirect'])->with('error', __('Could not connect your :provider account. Please try again.', [
                        'provider' => ucfirst($provider),
                    ]));
                }
            }

            if ($error) {
                return redirect()->to($payload['redirect'])->with('error', __('Connection cancelled or denied by the provider.'));
            }
        }

        return redirect()->to($payload['redirect'] ?? route('my-cloud.show'))
            ->with('error', __('Connection failed. Please try again.'));
    }

    public function disconnect(Request $request, string $provider)
    {
        if (! in_array($provider, UserCloudConnection::PROVIDERS, true)) {
            abort(404);
        }

        $scope = $request->input('scope', $request->query('scope', 'calendar'));
        if (! in_array($scope, UserCloudConnection::SCOPES, true)) {
            abort(422);
        }

        UserCloudConnection::where('user_id', auth()->id())
            ->where('provider', $provider)
            ->where('scope', $scope)
            ->delete();

        return redirect()->back()->with('success', __(':provider :scope disconnected.', [
            'provider' => ucfirst($provider),
            'scope' => $scope,
        ]));
    }
}
