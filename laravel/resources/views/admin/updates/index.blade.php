@extends('layouts.admin')
@section('title', 'Update Center — AEC LMS')
@section('content')
<div class="space-y-6 max-w-4xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Universal Update Center</h1>
        <p class="text-xs text-slate-500">Current Core Version: <strong>{{ $coreVersion }}</strong>. All packages require SHA-256 checksum & HMAC signature verification and trigger a pre-update backup.</p>
    </div>

    <form method="POST" action="{{ route('admin.updates.apply') }}" enctype="multipart/form-data" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
        @csrf
        <div class="grid sm:grid-cols-3 gap-4">
            <label class="block font-semibold">Signed ZIP Package
                <input type="file" name="package" required accept=".zip" class="mt-1 w-full">
            </label>
            <label class="block font-semibold">SHA-256 Checksum
                <input type="text" name="sha256" required maxlength="64" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
            </label>
            <label class="block font-semibold">HMAC-SHA256 Signature
                <input type="text" name="signature" required maxlength="64" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
            </label>
        </div>
        <button class="px-4 py-2 rounded-lg bg-sky-600 text-white font-bold">Verify & Apply Signed Update</button>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <h2 class="text-sm font-bold mb-3">Update Execution History</h2>
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Target</th><th>Version</th><th>Status</th><th>Backup Ref</th><th>Timestamp</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($logs as $log)
                    <tr>
                        <td class="py-2">{{ $log->target_type }}:{{ $log->target_slug }}</td>
                        <td>{{ $log->from_version ?? 'new' }} &rarr; {{ $log->to_version }}</td>
                        <td>{{ $log->status }}</td>
                        <td class="font-mono">{{ $log->backup_reference }}</td>
                        <td>{{ $log->created_at }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-slate-400">No update history yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
