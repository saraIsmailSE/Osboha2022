<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rate;
use App\Traits\ResponseJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Http\Resources\RateResource;

class RateController extends Controller
{
    use ResponseJson;

    public function index()
    {
        $rates = Rate::where('user_id', Auth::id())->get();
        if($rates){
            return $this->jsonResponseWithoutMessage(RateResource::collection($rates), 'data',200);
        }
        else{
            throw new NotFound;
        }
    }
    public function create(Request $request){
        $validator = Validator::make($request->all(), [
            'post_id' => 'required_without:comment_id',
            'comment_id' => 'required_without:post_id',
            'rate' => 'required',

        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }
            $request['user_id']= Auth::id();    
            Rate::create($request->all());
            return $this->jsonResponseWithoutMessage("Rate Craeted Successfully", 'data', 200);

    }
    public function show(Request $request)
    { 
        $validator = Validator::make($request->all(), [
            'comment_id' => 'required_without:post_id',
            'post_id' => 'required_without:comment_id',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }
        if($request->has('comment_id'))
         $rate = Rate::where('comment_id', $request->comment_id)->get();
        else if($request->has('post_id'))
         $rate = Rate::where('post_id', $request->post_id)->get();
        if($rate){
            return $this->jsonResponseWithoutMessage(RateResource::collection($rate), 'data',200);
        }
        else{
            throw new NotFound;
        }
    }
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rate' => 'required',
            'comment_id' => 'required_without:post_id',
            'post_id' => 'required_without:comment_id',
        ]);
        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }
        if($request->has('comment_id'))
         $rate = Rate::where('user_id', Auth::id())->where('comment_id', $request->comment_id)->first();
        else if($request->has('post_id'))
         $rate = Rate::where('user_id', Auth::id())->where('post_id', $request->post_id)->first();
        if($rate){
            $rate->update($request->all());
            return $this->jsonResponseWithoutMessage("Rate Updated Successfully", 'data', 200);
        }
        else{
            throw new NotFound;  
        }
    }
    public function delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'comment_id' => 'required_without:post_id',
            'post_id' => 'required_without:comment_id',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }  

        if($request->has('comment_id'))
         $rate = Rate::where('user_id', Auth::id())->where('comment_id', $request->comment_id)->first();
        else if($request->has('post_id'))
         $rate = Rate::where('user_id', Auth::id())->where('post_id', $request->post_id)->first();
        if($rate){
            $rate->delete();
            return $this->jsonResponseWithoutMessage("Rate Deleted Successfully", 'data', 200);
        }
        else{
            throw new NotFound;
        }
    }
}
