<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\Auth\AuthenticateUser;
use App\Traits\Auth\Register;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use Register, AuthenticateUser;

    
}
