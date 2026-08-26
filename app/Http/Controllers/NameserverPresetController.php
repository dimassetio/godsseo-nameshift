<?php

namespace App\Http\Controllers;

use App\Models\NameserverPreset;
use App\Services\Audit;
use App\Services\NameserverSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NameserverPresetController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/nameserver-presets', ['presets' => NameserverPreset::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $preset = NameserverPreset::create($data);
        Audit::record('nameserver_preset.created', $preset, ['name' => $preset->name]);

        return back()->with('success', 'Preset saved.');
    }

    public function update(Request $request, NameserverPreset $nameserverPreset): RedirectResponse
    {
        $data = $this->validated($request, $nameserverPreset);
        $nameserverPreset->update($data);
        Audit::record('nameserver_preset.updated', $nameserverPreset, ['name' => $nameserverPreset->name]);

        return back()->with('success', 'Preset updated.');
    }

    public function destroy(NameserverPreset $nameserverPreset): RedirectResponse
    {
        Audit::record('nameserver_preset.deleted', $nameserverPreset, ['name' => $nameserverPreset->name]);
        $nameserverPreset->delete();

        return back()->with('success', 'Preset deleted.');
    }

    private function validated(Request $request, ?NameserverPreset $preset = null): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('nameserver_presets')->ignore($preset)], 'nameservers' => ['required', 'array']]);
        $data['nameservers'] = NameserverSet::normalize($data['nameservers']);

        return $data;
    }
}
