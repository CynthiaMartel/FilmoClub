<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserFriend;
use App\Models\UserReport;
use App\Models\Rol;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    // ─── Listar usuarios con stats de moderación ───────────────────────────────

    public function index(Request $request)
    {
        $query = User::with(['role', 'profile'])
            ->withCount([
                // Cuántos usuarios distintos han bloqueado a este usuario
                'blockedBy as blocks_received_count',
                // Denuncias pendientes contra este usuario
                'reportsReceived as pending_reports_count' => fn ($q) =>
                    $q->where('status', 'pending')->where('low_confidence', false),
            ]);

        if ($request->filled('q')) {
            $search = '%' . $request->q . '%';
            $query->where(fn ($q) =>
                $q->where('name', 'like', $search)
                  ->orWhere('email', 'like', $search)
            );
        }

        if ($request->filled('role')) {
            $query->where('idRol', $request->role);
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'blocked' => $query->where('blocked', true),
                'active'  => $query->where('blocked', false),
                // has() usa un EXISTS subquery, compatible con paginate()
                'flagged' => $query->has('blockedBy', '>=', 3),
                default   => null,
            };
        }

        $users = $query->orderByDesc('created_at')->paginate(20);

        $users->getCollection()->transform(function (User $u) {
            return $this->formatUser($u);
        });

        return response()->json(['success' => 1, 'data' => $users]);
    }

    // ─── Detalle de un usuario ─────────────────────────────────────────────────

    public function show(User $user)
    {
        $user->loadCount([
            'blockedBy as blocks_received_count',
            'reportsReceived as total_reports_count',
            'reportsReceived as pending_reports_count' => fn ($q) =>
                $q->where('status', 'pending')->where('low_confidence', false),
        ]);

        $recentReports = UserReport::where('reported_user_id', $user->id)
            ->with(['reporter:id,name'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'reporter_name'  => $r->reporter->name ?? '(eliminado)',
                'category'       => $r->category,
                'reason'         => $r->reason,
                'status'         => $r->status,
                'low_confidence' => $r->low_confidence,
                'admin_note'     => $r->admin_note,
                'created_at'     => $r->created_at,
            ]);

        return response()->json([
            'success' => 1,
            'data'    => array_merge($this->formatUser($user), ['recent_reports' => $recentReports]),
        ]);
    }

    // ─── Cambiar rol ───────────────────────────────────────────────────────────

    public function changeRole(Request $request, User $user)
    {
        $admin = $request->user();

        if ($user->id === $admin->id) {
            return response()->json(['success' => 0, 'message' => 'No puedes cambiar tu propio rol.'], 422);
        }

        $request->validate(['role_id' => 'required|integer|exists:rols,id']);

        $user->idRol = $request->role_id;
        $user->save();

        return response()->json([
            'success' => 1,
            'message' => 'Rol actualizado correctamente.',
            'data'    => $this->formatUser($user->fresh('role', 'profile')),
        ]);
    }

    // ─── Listar todas las denuncias ────────────────────────────────────────────

    public function reports(Request $request)
    {
        $query = UserReport::with([
            'reporter:id,name',
            'reportedUser:id,name,email,blocked',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            // Por defecto, solo las pendientes y de confianza
            $query->where('status', 'pending')->where('low_confidence', false);
        }

        if ($request->boolean('include_low_confidence')) {
            $query->withoutGlobalScopes(); // muestra todo si admin pide ver low_confidence
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
        }

        $reports = $query->orderByDesc('created_at')->paginate(25);

        $reports->getCollection()->transform(fn ($r) => [
            'id'                 => $r->id,
            'reporter_name'      => $r->reporter->name ?? '(eliminado)',
            'reported_user_id'   => $r->reported_user_id,
            'reported_user_name' => $r->reportedUser->name ?? '(eliminado)',
            'reported_blocked'   => (bool) ($r->reportedUser->blocked ?? false),
            'category'           => $r->category,
            'reason'             => $r->reason,
            'status'             => $r->status,
            'low_confidence'     => $r->low_confidence,
            'admin_note'         => $r->admin_note,
            'created_at'         => $r->created_at,
        ]);

        return response()->json(['success' => 1, 'data' => $reports]);
    }

    // ─── Revisar una denuncia (desestimar / acción tomada) ─────────────────────

    public function reviewReport(Request $request, UserReport $report)
    {
        $request->validate([
            'status'     => 'required|in:reviewed,dismissed,actioned',
            'admin_note' => 'nullable|string|max:500',
        ]);

        $report->status     = $request->status;
        $report->admin_note = $request->admin_note ? strip_tags(trim($request->admin_note)) : null;
        $report->save();

        return response()->json(['success' => 1, 'message' => 'Denuncia actualizada.', 'data' => $report]);
    }

    // ─── Stats globales del panel ──────────────────────────────────────────────

    public function stats()
    {
        return response()->json([
            'success' => 1,
            'data'    => [
                'total_users'     => User::count(),
                'blocked_users'   => User::where('blocked', true)->count(),
                // has() con threshold usa un subquery COUNT, no HAVING sobre alias
                'flagged_users'   => User::has('blockedBy', '>=', 3)->count(),
                'pending_reports' => UserReport::where('status', 'pending')
                                               ->where('low_confidence', false)
                                               ->count(),
            ],
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function formatUser(User $u): array
    {
        return [
            'id'                   => $u->id,
            'name'                 => $u->name,
            'email'                => $u->email,
            'role'                 => optional($u->role)->rolType,
            'role_id'              => $u->idRol,
            'blocked'              => (bool) $u->blocked,
            'email_verified'       => !is_null($u->email_verified_at),
            'two_factor_enabled'   => $u->hasTwoFactorEnabled(),
            'last_access'          => $u->dateHourLastAccess,
            'created_at'           => $u->created_at,
            'blocks_received_count' => $u->blocks_received_count ?? 0,
            'pending_reports_count' => $u->pending_reports_count ?? 0,
            'flagged'              => ($u->blocks_received_count ?? 0) >= 3,
        ];
    }
}
