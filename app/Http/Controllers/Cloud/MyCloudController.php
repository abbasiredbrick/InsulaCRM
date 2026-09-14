<?php

namespace App\Http\Controllers\Cloud;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MyCloudController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $user->load('cloudConnections');

        return view('cloud.my', [
            'connections' => $user->cloudConnections->groupBy('scope'),
            'photoStorage' => $user->photo_storage ?? 'cloud',
            'googleConfigured' => $user->tenant->cloudProviderConfigured('google'),
            'microsoftConfigured' => $user->tenant->cloudProviderConfigured('microsoft'),
        ]);
    }

    public function updatePhotos(Request $request)
    {
        $request->validate([
            'photo_storage' => ['required', 'in:cloud,google,microsoft'],
        ]);

        $user = auth()->user();
        $user->photo_storage = $request->photo_storage;
        $user->save();

        \App\Models\AuditLog::log('user.photo_storage_updated', $user, ['storage' => $request->photo_storage]);

        return redirect()->route('my-cloud.show')->with('success', __('Photo storage preference saved.'));
    }
}
