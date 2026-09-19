<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\OpenHouse;
use App\Models\Showing;
use App\Models\Task;
use App\Services\BusinessModeService;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function index()
    {
        return view('calendar.index');
    }

    /**
     * Return events as JSON for the calendar (AJAX).
     */
    public function events(Request $request)
    {
        $user = auth()->user();
        $start = $request->input('start');
        $end = $request->input('end');

        $events = collect();

        // Tasks
        $tasksQuery = Task::with('lead')
            ->whereBetween('due_date', [$start, $end]);

        if (! $user->isAdmin()) {
            $tasksQuery->where('agent_id', $user->id);
        }

        $tasks = $tasksQuery->get()->map(fn ($task) => [
            'id' => 'task-'.$task->id,
            'title' => $task->title,
            'date' => $task->due_date->format('Y-m-d'),
            'time' => blank($task->due_time) ? null : \Carbon\Carbon::parse($task->due_time)->format('g:i A'),
            'type' => 'task',
            'color' => $task->is_completed ? 'green' : ($task->is_overdue ? 'red' : 'blue'),
            'completed' => $task->is_completed,
            'url' => $task->lead_id ? url("/leads/{$task->lead_id}") : null,
        ]);

        $events = $events->merge($tasks);

        // Meetings (scheduled client meetings/appointments)
        $meetingsQuery = Meeting::with('lead')
            ->where('status', 'scheduled')
            ->whereDate('scheduled_at', '>=', $start)
            ->whereDate('scheduled_at', '<=', $end);

        if (! $user->isAdmin()) {
            $meetingsQuery->where('agent_id', $user->id);
        }

        $meetingEvents = $meetingsQuery->get()->map(fn ($m) => [
            'id' => 'meeting-'.$m->id,
            'title' => __('Meeting').': '.$m->title,
            'date' => $m->scheduled_at->format('Y-m-d'),
            'type' => 'meeting',
            'color' => 'purple',
            'url' => $m->lead_id ? url("/leads/{$m->lead_id}") : null,
        ]);

        $events = $events->merge($meetingEvents);

        // Showings (real estate mode only)
        if (BusinessModeService::isRealEstate()) {
            $showingsQuery = Showing::with('property')
                ->where('status', 'scheduled')
                ->whereBetween('showing_date', [$start, $end]);

            if (! $user->isAdmin()) {
                $showingsQuery->where('agent_id', $user->id);
            }

            $showingEvents = $showingsQuery->get()->map(fn ($s) => [
                'id' => 'showing-'.$s->id,
                'title' => __('Viewing').': '.($s->property->address ?? ''),
                'date' => $s->showing_date->format('Y-m-d'),
                'type' => 'showing',
                'color' => 'orange',
                'url' => url("/showings/{$s->id}"),
            ]);

            $events = $events->merge($showingEvents);

            // Open Houses
            $openHouseQuery = OpenHouse::with('property')
                ->whereIn('status', ['scheduled', 'active'])
                ->whereBetween('event_date', [$start, $end]);

            if (! $user->isAdmin()) {
                $openHouseQuery->where('agent_id', $user->id);
            }

            $openHouseEvents = $openHouseQuery->get()->map(fn ($oh) => [
                'id' => 'openhouse-'.$oh->id,
                'title' => __('Open House').': '.($oh->property->address ?? ''),
                'date' => $oh->event_date->format('Y-m-d'),
                'type' => 'open_house',
                'color' => 'teal',
                'url' => url("/open-houses/{$oh->id}"),
            ]);

            $events = $events->merge($openHouseEvents);
        }

        return response()->json($events->values());
    }
}
