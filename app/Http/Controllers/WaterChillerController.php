<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WaterChillerCheck;
use App\Models\WaterChillerResult;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf; // Import Facade PDF

class WaterChillerController extends Controller
{
    public function index(Request $request)
    {
        $query = WaterChillerCheck::orderBy('tanggal', 'desc');

        // Filter berdasarkan peran user (Checker hanya bisa melihat data sendiri)
        if (Auth::user() instanceof \App\Models\Checker) {
            $query->where('checked_by', Auth::user()->username);
        }

        // Filter berdasarkan bulan jika ada
        if ($request->filled('bulan')) {
            $bulan = date('m', strtotime($request->bulan));
            $tahun = date('Y', strtotime($request->bulan));
            $query->whereMonth('tanggal', $bulan)
                ->whereYear('tanggal', $tahun);
        }

        // Filter berdasarkan nama checker jika ada
        if ($request->filled('search')) {
            $query->where('checked_by', 'LIKE', '%' . $request->search . '%');
        }

        // Ambil data dengan paginasi dan pastikan parameter tetap diteruskan
        $checks = $query->paginate(10)->appends($request->query());

        return view('water_chiller.index', compact('checks'));
    }

    public function create()
    {
        return view('water_chiller.create');
    }

    public function store(Request $request)
    {
        // Cek apakah tanggal sudah ada di database
        $existingDate = WaterChillerCheck::where('tanggal', $request->tanggal)
        ->when(Auth::user() instanceof \App\Models\Checker, function ($query) {
            // Jika user adalah Checker, hanya cek recordnya sendiri
            return $query->where('checked_by', Auth::user()->username);
        })
        ->exists();
    
        if ($existingDate) {
            return redirect()->route('water-chiller.create')->with('warning', 'Data di tanggal tersebut telah dibuat');
        }
        $request->validate([
            'tanggal' => 'required|date',
            'hari' => 'required|string|max:20',
            'keterangan' => 'nullable|string',
        ]);

        $check = WaterChillerCheck::create([
            'tanggal' => $request->tanggal,
            'hari' => $request->hari,
            'checked_by' => Auth::user()->username,
            'approved_by' => null,
            'keterangan' => $request->keterangan,
        ]);

        for ($i = 1; $i <= 32; $i++) {
            WaterChillerResult::create([
                'check_id' => $check->id,
                'no_mesin' => "CH{$i}",
                'Temperatur_Compressor' => $request->input("temperatur_1.{$i}") ?: null,
                'Temperatur_Kabel' => $request->input("temperatur_2.{$i}") ?: null,
                'Temperatur_Mcb' => $request->input("temperatur_3.{$i}") ?: null,
                'Temperatur_Air' => $request->input("temperatur_4.{$i}") ?: null,
                'Temperatur_Pompa' => $request->input("temperatur_5.{$i}") ?: null,
                'Evaporator' => $request->input("evaporator.{$i}") ?: null,
                'Fan_Evaporator' => $request->input("fan_evaporator.{$i}") ?: null,
                'Freon' => $request->input("freon.{$i}") ?: null,
                'Air' => $request->input("air.{$i}") ?: null,
            ]);
        }

        return redirect()->route('water-chiller.index')->with('success', 'Data berhasil disimpan');
    }

    public function edit($check_id)
    {
        $check = WaterChillerCheck::findOrFail($check_id);
        $results = WaterChillerResult::where('check_id', $check_id)->get();
        return view('water_chiller.edit', compact('check', 'results'));
    }

    public function update(Request $request, $check_id)
    {
        $request->validate([
            'tanggal' => 'required|date',
            'hari' => 'required|string|max:20',
            'keterangan' => 'nullable|string',
        ]);

        // Update WaterChillerCheck
        $check = WaterChillerCheck::findOrFail($check_id);
        $check->update([
            'tanggal' => $request->tanggal,
            'hari' => $request->hari,
            'keterangan' => $request->keterangan,
        ]);

        // Update WaterChillerResult
        if ($request->has('no_mesin')) {
            foreach ($request->no_mesin as $resultId => $no_mesin) {
                $result = WaterChillerResult::findOrFail($resultId);
                
                $result->update([
                    'Temperatur_Compressor' => $request->input("temperatur_1.{$resultId}") ?: null,
                    'Temperatur_Kabel' => $request->input("temperatur_2.{$resultId}") ?: null,
                    'Temperatur_Mcb' => $request->input("temperatur_3.{$resultId}") ?: null,
                    'Temperatur_Air' => $request->input("temperatur_4.{$resultId}") ?: null,
                    'Temperatur_Pompa' => $request->input("temperatur_5.{$resultId}") ?: null,
                    'Evaporator' => $request->input("evaporator.{$resultId}") ?: null,
                    'Fan_Evaporator' => $request->input("fan_evaporator.{$resultId}") ?: null,
                    'Freon' => $request->input("freon.{$resultId}") ?: null,
                    'Air' => $request->input("air.{$resultId}") ?: null,
                ]);
            }
        }

        return redirect()->route('water-chiller.index')->with('success', 'Data berhasil diperbarui!');
    }

    public function show($check_id)
    {
        $check = WaterChillerCheck::findOrFail($check_id);
        $results = WaterChillerResult::where('check_id', $check_id)->get();
        
        return view('water_chiller.show', compact('check', 'results'));
    }

    public function approve(Request $request, $check_id)
    {
        $check = WaterChillerCheck::findOrFail($check_id);
        
        // Update approved_by field dengan username approver yang login
        $check->update([
            'approved_by' => Auth::user()->username
        ]);
        
        return redirect()->route('water-chiller.index')
            ->with('success', 'Data berhasil disetujui!');
    }

    public function downloadPdf($id)
    {
        // Ambil data dari database berdasarkan ID
        $check = WaterChillerCheck::findOrFail($id);
        $results = WaterChillerResult::where('check_id', $id)->get();
        
        // Load view untuk PDF dengan ukuran halaman yang sesuai
        $pdf = Pdf::loadView('water_chiller.pdf', compact('check', 'results'))
            ->setPaper('a4', 'landscape'); // Set ukuran kertas A4 landscape
    
        // Format tanggal untuk nama file
        $formattedDate = date('d-m-Y', strtotime($check->tanggal));

        // Mengembalikan file PDF untuk di-download dengan format nama yang baru
        return $pdf->download('Water Chiller Form_' . $formattedDate . '.pdf');
    }
}