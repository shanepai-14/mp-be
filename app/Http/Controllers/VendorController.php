<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorController extends Controller
{

    public function create(Request $request)
    {
        $exist = Vendor::where('vendor_name', $request->name)->where('vendor_code', $request->vendor_code)->exists();

        if ($exist)
            return new JsonResource([
                'status' => true,
                'message' => 'Vendor already exist'
            ], 409);

        else {
            $newVendor = Vendor::create($request->all());
            return $newVendor;
        }
    }
}