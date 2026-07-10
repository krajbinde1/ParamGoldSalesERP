<?php

namespace App\Http\Controllers;

use App\Models\Dealer;
use Illuminate\Http\Request;

class DealerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $dealers = Dealer::latest()->paginate(10);

        return view('dealers.index', compact('dealers'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('dealers.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'firm_name'         => 'required|max:255',
            'owner_name'        => 'required|max:255',
            'mobile'            => 'required|max:20',
            'alternate_mobile'  => 'nullable|max:20',
            'email'             => 'nullable|email',
            'gst_no'            => 'nullable|max:20',
            'address'           => 'required',
            'state'             => 'required',
            'district'          => 'required',
            'taluka'            => 'required',
            'village'           => 'nullable',
            'pincode'           => 'required|max:10',
            'credit_limit'      => 'nullable|numeric',
        ]);

        $lastDealer = Dealer::latest()->first();

        if ($lastDealer) {
            $number = (int) substr($lastDealer->dealer_code, 3) + 1;
        } else {
            $number = 1;
        }

        $validated['dealer_code'] = 'DLR' . str_pad($number, 6, '0', STR_PAD_LEFT);
        $validated['outstanding'] = 0;
        $validated['status'] = true;

        Dealer::create($validated);

        return redirect()->route('dealers.index')
            ->with('success', 'Dealer Created Successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Dealer $dealer)
    {
        return view('dealers.show', compact('dealer'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Dealer $dealer)
    {
        return view('dealers.edit', compact('dealer'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Dealer $dealer)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Dealer $dealer)
    {
        $dealer->delete();

        return redirect()->route('dealers.index')
            ->with('success', 'Dealer Deleted Successfully.');
    }
}