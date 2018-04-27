<?php

namespace App\Http\Controllers;

use App\User;
use Illuminate\Http\Request;

class testController extends Controller
{
    public function test(Request $request)
    {
            $res = User::all();
            $res = User::find(1);
            $res = User::where(['a'=>1])->where('a',1)->where("a","<","1")->get();
            $res = User::where(['a'=>1])->where('a',1)->where("a","<","1")->first();
            $res = User::where(['a'=>1])->where('a',1)->where("a","<","1")->limit(10);
            $res = User::where(['a'=>1])->where('a',1)->value('name');
            $res = User::query("select * from user");
            dd($res);
    }

    public function message()
    {
        User::with('messages');
        app(User::class)->messages();

    }
}
