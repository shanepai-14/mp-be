<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorController extends Controller
{

    public function create(Request $request)
    {
        $isVendorExist = Vendor::where('vendor_code', $request->vendor_code)->exists();

        if ($isVendorExist)
            return new JsonResource([
                'status' => true,
                'message' => 'Vendor already exist'
            ], 409);

        else {
            $newVendor = Vendor::create([
                'vendor_name' => $request->vendor_name,
                'vendor_address' => $request->vendor_address,
                'vendor_contact_no' => $request->vendor_contact_no,
                'vendor_code' => $request->vendor_code,
                'vendor_key' => $request->vendor_key
            ]);

            $response = new ApiResponse();

            if ($newVendor) {
                $responseData = [
                    'vendor' => $newVendor
                ];
                return $response->SuccessResponse('Vendor successfully registered', $responseData);
            } else
                return $response->ErrorResponse('Server Error');
        }
    }

    public function list()
    {
        return Vendor::all();
    }

    public function vendorById($id)
    {
        return Vendor::find($id);
    }
}
