<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Permissions;
use App\Events\WatchPartySessionUpdated;

class WatchPartyPluginController
{
    /**
     * GET /api/plugins/watch-party/proposals?channel_id=...
     */
    public function index(Request $request)
    {
        $channelId = $request->query('channel_id', '');
        if (!$channelId) {
            return response()->json(['proposals' => []]);
        }

        $member  = $request->attributes->get('member');
        $voterId = $member?->central_user_id ?? '';
        $canMod  = $member && ($member->isAdmin() || $member->can(Permissions::WATCH_PARTY_MODERATE));

        $query = DB::table('watch_proposals')->where('channel_id', $channelId);
        if (!$canMod) {
            $query->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('watch_approvals')
                    ->whereColumn('watch_approvals.proposal_id', 'watch_proposals.id');
            });
        }

        $proposals = $query->orderByDesc('created_at')->get()->map(function ($p) use ($voterId, $canMod) {
            $votes    = DB::table('watch_votes')->where('proposal_id', $p->id)->count();
            $voted    = $voterId ? DB::table('watch_votes')->where('proposal_id', $p->id)->where('voter_id', $voterId)->exists() : false;
            $approval = DB::table('watch_approvals')->where('proposal_id', $p->id)->first();

            return [
                'id'          => $p->id,
                'url'         => $p->url,
                'title'       => $p->title,
                'proposed_by' => $p->proposed_by,
                'is_mine'     => $voterId && $p->proposed_by_id === $voterId,
                'votes'       => $votes,
                'voted'       => $voted,
                'is_approved' => $approval !== null,
                'approved_by' => $approval?->approved_by,
                'created_at'  => $p->created_at,
            ];
        })->sortByDesc('votes')->values();

        return response()->json(['proposals' => $proposals]);
    }

    /**
     * POST /api/plugins/watch-party/proposals
     */
    public function propose(Request $request)
    {
        $member = $request->attributes->get('member');
        if (!$member) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        if (!($member->isAdmin() || $member->can(Permissions::WATCH_PARTY_PROPOSE))) {
            return response()->json(['message' => 'You do not have permission to propose videos.'], 403);
        }

        $validated = $request->validate([
            'channel_id' => ['required', 'string'],
            'url'        => ['required', 'url', 'max:2048'],
            'title'      => ['nullable', 'string', 'max:512'],
        ]);

        $existing = DB::table('watch_proposals')
            ->where('channel_id', $validated['channel_id'])
            ->where('url', $validated['url'])
            ->first();

        if ($existing) {
            $alreadyVoted = DB::table('watch_votes')
                ->where('proposal_id', $existing->id)
                ->where('voter_id', $member->central_user_id)
                ->exists();

            if (!$alreadyVoted) {
                DB::table('watch_votes')->insert([
                    'proposal_id' => $existing->id,
                    'voter_id'    => $member->central_user_id,
                ]);
            }

            $votes = DB::table('watch_votes')->where('proposal_id', $existing->id)->count();
            return response()->json(['ok' => true, 'id' => $existing->id, 'deduplicated' => true, 'voted' => true, 'votes' => $votes]);
        }

        $userCount = DB::table('watch_proposals')
            ->where('channel_id', $validated['channel_id'])
            ->where('proposed_by_id', $member->central_user_id)
            ->count();

        if ($userCount >= 3) {
            return response()->json(['message' => 'You already have 3 active proposals. Remove one first.'], 422);
        }

        $id = DB::table('watch_proposals')->insertGetId([
            'channel_id'     => $validated['channel_id'],
            'url'            => $validated['url'],
            'title'          => $validated['title'] ?? null,
            'proposed_by'    => $member->username,
            'proposed_by_id' => $member->central_user_id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        if ($member->isAdmin() || $member->can(Permissions::WATCH_PARTY_MODERATE)) {
            DB::table('watch_approvals')->insertOrIgnore([
                'proposal_id' => $id,
                'approved_by' => $member->username,
                'approved_at' => now(),
            ]);
        }

        return response()->json(['ok' => true, 'id' => $id, 'deduplicated' => false], 201);
    }

    /**
     * POST /api/plugins/watch-party/proposals/{id}/approve
     */
    public function approve(Request $request, int $id)
    {
        $member = $request->attributes->get('member');
        if (!$member) return response()->json(['message' => 'Unauthorized.'], 401);
        if (!($member->isAdmin() || $member->can(Permissions::WATCH_PARTY_MODERATE))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $proposal = DB::table('watch_proposals')->where('id', $id)->first();
        if (!$proposal) return response()->json(['message' => 'Proposal not found.'], 404);

        DB::table('watch_approvals')->insertOrIgnore([
            'proposal_id' => $id,
            'approved_by' => $member->username,
            'approved_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/plugins/watch-party/proposals/{id}/approve  (reject)
     */
    public function reject(Request $request, int $id)
    {
        $member = $request->attributes->get('member');
        if (!$member) return response()->json(['message' => 'Unauthorized.'], 401);
        if (!($member->isAdmin() || $member->can(Permissions::WATCH_PARTY_MODERATE))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        DB::table('watch_approvals')->where('proposal_id', $id)->delete();
        DB::table('watch_votes')->where('proposal_id', $id)->delete();
        DB::table('watch_proposals')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/plugins/watch-party/proposals/{id}/vote
     */
    public function vote(Request $request, int $id)
    {
        $member = $request->attributes->get('member');
        if (!$member) return response()->json(['message' => 'Unauthorized.'], 401);

        $proposal = DB::table('watch_proposals')->where('id', $id)->first();
        if (!$proposal) return response()->json(['message' => 'Proposal not found.'], 404);

        $exists = DB::table('watch_votes')
            ->where('proposal_id', $id)
            ->where('voter_id', $member->central_user_id)
            ->exists();

        if ($exists) {
            DB::table('watch_votes')->where('proposal_id', $id)->where('voter_id', $member->central_user_id)->delete();
            $voted = false;
        } else {
            DB::table('watch_votes')->insert(['proposal_id' => $id, 'voter_id' => $member->central_user_id]);
            $voted = true;
        }

        $votes = DB::table('watch_votes')->where('proposal_id', $id)->count();
        return response()->json(['ok' => true, 'voted' => $voted, 'votes' => $votes]);
    }

    /**
     * DELETE /api/plugins/watch-party/proposals/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $member = $request->attributes->get('member');
        if (!$member) return response()->json(['message' => 'Unauthorized.'], 401);

        $proposal = DB::table('watch_proposals')->where('id', $id)->first();
        if (!$proposal) return response()->json(['message' => 'Not found.'], 404);

        if ($proposal->proposed_by_id !== $member->central_user_id && !$member->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        DB::table('watch_approvals')->where('proposal_id', $id)->delete();
        DB::table('watch_votes')->where('proposal_id', $id)->delete();
        DB::table('watch_proposals')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/plugins/watch-party/proposals?channel_id=...
     */
    public function clear(Request $request)
    {
        $member = $request->attributes->get('member');
        if (!$member?->isAdmin()) return response()->json(['message' => 'Admin only.'], 403);

        $channelId = $request->query('channel_id', '');
        if (!$channelId) return response()->json(['message' => 'channel_id required.'], 422);

        $ids = DB::table('watch_proposals')->where('channel_id', $channelId)->pluck('id');
        DB::table('watch_approvals')->whereIn('proposal_id', $ids)->delete();
        DB::table('watch_votes')->whereIn('proposal_id', $ids)->delete();
        DB::table('watch_proposals')->where('channel_id', $channelId)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/plugins/watch-party/session/{channelId}
     */
    public function session(Request $request, string $channelId)
    {
        $session = DB::table('watch_sessions')->where('channel_id', $channelId)->first();
        if (!$session) return response()->json(['session' => null]);
        return response()->json(['session' => $this->formatSession($session)]);
    }

    /**
     * POST /api/plugins/watch-party/session
     */
    public function syncSession(Request $request)
    {
        $member = $request->attributes->get('member');
        if (!$member) return response()->json(['message' => 'Unauthorized.'], 401);
        if (!($member->isAdmin() || $member->can(Permissions::WATCH_PARTY_CONTROL))) {
            return response()->json(['message' => 'You do not have permission to control playback.'], 403);
        }

        $validated = $request->validate([
            'channel_id'  => ['required', 'string'],
            'action'      => ['required', 'string', 'in:start,pause,resume,seek,stop'],
            'proposal_id' => ['nullable', 'integer'],
            'timecode'    => ['nullable', 'numeric', 'min:0'],
        ]);

        $channelId = $validated['channel_id'];
        $action    = $validated['action'];
        $now       = now();

        if ($action === 'start') {
            $proposal = $validated['proposal_id']
                ? DB::table('watch_proposals')->where('id', $validated['proposal_id'])->first()
                : null;
            if (!$proposal) return response()->json(['message' => 'Proposal not found.'], 422);

            $syncAt = $now->copy()->addSeconds(5);
            DB::table('watch_sessions')->updateOrInsert(
                ['channel_id' => $channelId],
                [
                    'proposal_id'   => $proposal->id,
                    'url'           => $proposal->url,
                    'title'         => $proposal->title,
                    'state'         => 'synchronising',
                    'timecode'      => 0,
                    'timecode_at'   => $syncAt,
                    'sync_at'       => $syncAt,
                    'controlled_by' => $member->username,
                    'updated_at'    => $now,
                ]
            );
        } elseif ($action === 'pause') {
            $session = DB::table('watch_sessions')->where('channel_id', $channelId)->first();
            if (!$session || $session->state !== 'playback') return response()->json(['message' => 'Not playing.'], 422);
            $elapsed  = $session->timecode_at ? $now->diffInSeconds($session->timecode_at, false) : 0;
            $timecode = max(0, $session->timecode + $elapsed);
            DB::table('watch_sessions')->where('channel_id', $channelId)->update([
                'state' => 'paused', 'timecode' => $timecode, 'timecode_at' => $now, 'updated_at' => $now,
            ]);
        } elseif ($action === 'resume') {
            $session = DB::table('watch_sessions')->where('channel_id', $channelId)->first();
            if (!$session || $session->state !== 'paused') return response()->json(['message' => 'Not paused.'], 422);
            DB::table('watch_sessions')->where('channel_id', $channelId)->update([
                'state' => 'playback', 'timecode_at' => $now, 'updated_at' => $now,
            ]);
        } elseif ($action === 'seek') {
            $session = DB::table('watch_sessions')->where('channel_id', $channelId)->first();
            if (!$session || !in_array($session->state, ['playback', 'paused'])) {
                return response()->json(['message' => 'No active session.'], 422);
            }
            DB::table('watch_sessions')->where('channel_id', $channelId)->update([
                'timecode' => $validated['timecode'] ?? 0, 'timecode_at' => $now, 'updated_at' => $now,
            ]);
        } elseif ($action === 'stop') {
            DB::table('watch_sessions')->where('channel_id', $channelId)->delete();
            broadcast(new WatchPartySessionUpdated(['channel_id' => $channelId, 'state' => 'idle']));
            return response()->json(['ok' => true, 'session' => null]);
        }

        $session = DB::table('watch_sessions')->where('channel_id', $channelId)->first();
        $payload = $this->formatSession($session);
        broadcast(new WatchPartySessionUpdated($payload));

        return response()->json(['ok' => true, 'session' => $payload]);
    }

    /**
     * POST /api/plugins/watch-party/session/ready
     */
    public function sessionReady(Request $request)
    {
        $member = $request->attributes->get('member');
        if (!$member) return response()->json(['message' => 'Unauthorized.'], 401);
        if (!($member->isAdmin() || $member->can(Permissions::WATCH_PARTY_CONTROL))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $channelId = $request->input('channel_id', '');
        $session   = DB::table('watch_sessions')->where('channel_id', $channelId)->first();

        if (!$session || $session->state !== 'synchronising') {
            return response()->json(['message' => 'Not in synchronising state.'], 422);
        }

        $now = now();
        DB::table('watch_sessions')->where('channel_id', $channelId)->update([
            'state' => 'playback', 'timecode' => 0, 'timecode_at' => $now, 'updated_at' => $now,
        ]);

        $session = DB::table('watch_sessions')->where('channel_id', $channelId)->first();
        $payload = $this->formatSession($session);
        broadcast(new WatchPartySessionUpdated($payload));

        return response()->json(['ok' => true, 'session' => $payload]);
    }

    private function formatSession(object $session): array
    {
        return [
            'channel_id'    => $session->channel_id,
            'proposal_id'   => $session->proposal_id,
            'url'           => $session->url,
            'title'         => $session->title ?? null,
            'state'         => $session->state,
            'timecode'      => (float) $session->timecode,
            'timecode_at'   => $session->timecode_at,
            'sync_at'       => $session->sync_at ?? null,
            'controlled_by' => $session->controlled_by,
            'updated_at'    => $session->updated_at,
        ];
    }
}
