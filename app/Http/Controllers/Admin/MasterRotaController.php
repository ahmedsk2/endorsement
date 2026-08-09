<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Period;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Master Rota (cap:rota.manage). Munawib MR-02/MR-03.
 *
 * P1d-1 Task 1 ships this route, its capability gate and a teaching empty state naming
 * Structure → Periods — the rota's columns ARE periods (P1b), so a department with none
 * generated yet has nowhere to plant an assignment. The grid itself (person rows, period
 * columns, per-cell unit/split/vacation editing) is Task 8, not this task; `grid` is `null`
 * until then by construction.
 */
class MasterRotaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/MasterRota', [
            'academic_years' => Period::query()
                ->select('academic_year')
                ->distinct()
                ->orderBy('academic_year')
                ->pluck('academic_year'),
            'grid' => null,
        ]);
    }
}
