<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminAnnouncementController extends Controller
{
    public function index()
    {
        $announcements = Announcement::with('plans:id,name')->latest()->paginate(20);

        return view('admin.announcements.index', compact('announcements'));
    }

    public function create()
    {
        return view('admin.announcements.form', [
            'announcement' => new Announcement(['audience' => 'all', 'is_active' => true]),
            'plans' => Plan::orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->save($request, new Announcement());

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement created.');
    }

    public function edit(Announcement $announcement)
    {
        return view('admin.announcements.form', [
            'announcement' => $announcement,
            'plans' => Plan::orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->save($request, $announcement);

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return back()->with('success', 'Announcement deleted.');
    }

    private function save(Request $request, Announcement $announcement): void
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'audience' => ['required', Rule::in(array_keys(Announcement::AUDIENCES))],
            'plan_ids' => ['nullable', 'array', 'required_if:audience,plans'],
            'plan_ids.*' => ['integer', 'exists:plans,id'],
        ], [
            'plan_ids.required_if' => 'Select at least one plan.',
        ], [
            'title' => 'Title',
            'body' => 'Announcement text',
            'audience' => 'Audience',
        ]);

        $announcement->fill([
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'is_active' => $request->boolean('is_active'),
        ])->save();

        $announcement->plans()->sync($data['audience'] === 'plans' ? ($data['plan_ids'] ?? []) : []);
    }
}
