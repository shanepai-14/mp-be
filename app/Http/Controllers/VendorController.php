<?php

namespace App\Http\Controllers;

use App\Http\Response\ApiResponse;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;



class VendorController extends Controller
{
    public function create(Request $request)
    {
        $response = new ApiResponse();
        $isVendorExist = Vendor::where('vendor_code', $request->vendor_code)->exists();

        if ($isVendorExist)
            return $response->ErrorResponse('Vendor already exist!', 409);

        else {
            $newVendor = Vendor::create([
                'vendor_name' => $request->vendor_name,
                'vendor_address' => $request->vendor_address,
                'vendor_contact_no' => $request->vendor_contact_no,
                'vendor_code' => $request->vendor_code,
                'vendor_email' => $request->vendor_email,
                'vendor_key' => Str::uuid()->toString()
            ]);

            if ($newVendor) {
                $responseData = ['vendor' => $newVendor];
                return $response->SuccessResponse('Vendor is successfully registered', $responseData);
            }

            return $response->ErrorResponse('Server Error', 500);
        }
    }

    public function list()
    {
        return Vendor::all();
    }

    public function vendorById($id)
    {
        $vendor = Vendor::find($id);

        if ($vendor) return $vendor;

        $response = new ApiResponse();
        return $response->ErrorResponse('Vendor not found!', 404);
    }

    public function update($id, Request $request)
    {
        $response = new ApiResponse();

        if ($id == $request->id) {
            $vendor = Vendor::find($id);

            if ($vendor) {
                $vendor->update($request->all());
                return $response->SuccessResponse('Vendor is successfully updated!', $vendor);
            }

            return $response->ErrorResponse('Vendor not found!', 404);
        }

        return $response->ErrorResponse('Vendor Id does not matched!', 400);
    }

    public function delete($id)
    {
        $response = new ApiResponse();
        $vendor = Vendor::find($id);

        if ($vendor) {
            $vendor->delete();
            return $response->SuccessResponse('Vendor is successfully deleted!', $vendor); 
        } 

        return $response->ErrorResponse('Vendor does not exist!', 404);
    }
}
