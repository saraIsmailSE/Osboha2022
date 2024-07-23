<?php

namespace App\Http\Controllers\Api;

use App\Events\NotificationsEvent;
use App\Exceptions\NotAuthorized;
use App\Exceptions\NotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserGroupResource;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\Request;
use App\Traits\ResponseJson;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use App\Models\Group;
use App\Models\Mark;
use App\Models\UserBook;
use App\Models\UserParent;
use App\Models\Week;
use App\Notifications\MailAmbassadorDistribution;
use App\Notifications\MailMemberAdd;
use App\Traits\PathTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;




class UserGroupController extends Controller
{
    use ResponseJson, PathTrait;
    /**
     * Read all user groups in the system.
     *
     * @return jsonResponseWithoutMessage
     */
    public function index()
    {
        #####Asmaa####
        $userGroups = UserGroup::all();

        if ($userGroups->isNotEmpty()) {
            return $this->jsonResponseWithoutMessage(UserGroupResource::collection($userGroups), 'data', 200);
        } else {
            throw new NotFound;
        }
    }
    /**
     * Find an existing user group in the system by its id and display it.
     *
     * @param  Request  $request
     * @return jsonResponseWithoutMessage;
     */
    public function show(Request $request)
    {
        #####Asmaa####
        $validator = Validator::make($request->all(), ['user_group_id' => 'required']);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        $userGroup = UserGroup::find($request->user_group_id);

        if ($userGroup) {
            return $this->jsonResponseWithoutMessage(new UserGroupResource($userGroup), 'data', 200);
        } else {
            throw new NotFound;
        }
    }
    /**
     * Get all users in specific group.
     *
     * @param  $group_id
     * @return jsonResponseWithoutMessage;
     */
    public function usersByGroupID($group_id)
    {
        $users = Group::with('users')->where('id', $group_id)->first();
        if ($users) {
            return $this->jsonResponseWithoutMessage($users, 'data', 200);
        } else {
            throw new NotFound;
        }
    }

    /**
     * Assign role to specific user with add him/her to group.
     * after that,this user will receive a new notification about his/her new role and group(“assgin role” permission is required).
     *
     * @param  Request  $request
     * @return jsonResponseWithoutMessage;
     */



    public function create(Request $request)
    {
        // Validate the input
        $validatedData = $request->validate([
            'email' => 'required|email',
            'group_id' => 'required',
            'user_type' => 'required',
        ]);


        $user = User::where('email', $validatedData['email']);
        if (!$user) {
            return $this->jsonResponseWithoutMessage('email not found', 'data', 404);
        } else if (!is_null($user->parent_id)) {
            $user->parent_id = Auth::id();
        } else if (!$user->hasRole($validatedData['uesr_type'])) {
            return $this->jsonResponseWithoutMessage('User does not have the required role', 'data', 401);
        }

        $user->save();
        $userGroup = UserGroup::create(['user_id' => $user->id, 'group_id' =>  $validatedData['group_id'], $validatedData['uesr_type']]);

        $userGroup->save();


        return response()->json([
            'status' => 'success',
            'message' => 'User added successfully',
            'data' => $user,
        ]);
    }



    /**
     * Add user to group with specific role
     *
     * @param  Request  $request
     * @return jsonResponseWithoutMessage;
     */

    public function addMember(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'group_id' => 'required',
                'email' => 'required|email',
                'role_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        ########## check role exists ##########
        $role = Role::find($request->role_id);
        if (!$role) {
            return $this->jsonResponseWithoutMessage("هذه الرتبة غير موجودة", 'data', 500);
        }

        $user = User::where('email', $request->email)->first();
        if ($user) {

            if ($user->is_excluded || $user->is_hold || is_null($user->email_verified_at)) {
                return $this->jsonResponseWithoutMessage('لا يمكن اضافة هذا العضو لأنه غير فعال [عضو مستبعد أو منسحب أم لم يقم بتأكيد البريد الالكتروني]', 'data', 200);
            }

            $group = Group::find($request->group_id);
            if ($group) {
                $arabicRole = config('constants.ARABIC_ROLES')[$role->name];

                if (in_array($group->type->type, ['advanced_followup', 'sophisticated_followup']) && $role->name == 'leader') {
                    return $this->handleLeaderRole($user, $group, $role, $arabicRole);
                }


                if ($user->hasRole($role->name)) {

                    ########## check if USER exists in the group with the same role ##########
                    $checkMember = UserGroup::where('user_id', $user->id)
                        ->where('group_id', $group->id)
                        ->where('user_type', $role->name)
                        ->whereNull('termination_reason')->first();
                    if ($checkMember) {
                        return $this->jsonResponseWithoutMessage('ال' . $arabicRole .  ' موجود في المجموعة', 'data', 200);
                    }

                    ########## Handel Ambassador Role ##########
                    if ($role->name == 'ambassador') {
                        return $this->handleAmbassadorRole($user, $group, $role, $arabicRole);
                    }
                    ########## Handel Marathon Ambassador Role ##########
                    if ($role->name == 'marathon_ambassador') {
                        return $this->handleMarathonAmbassadorRole($user, $group, $role, $arabicRole);
                    }

                    ########## Handel Leader, Support Leader, marathon_supervisor Roles ##########

                    if (in_array($role->name, ['leader', 'support_leader', 'marathon_supervisor', 'special_care_leader'])) {
                        return $this->handleLeaderRole($user, $group, $role, $arabicRole);
                    }
                    ########## Handel Supervisor Roles ##########

                    if (in_array($role->name, ['supervisor', 'special_care_supervisor'])) {
                        if ($user->hasRole('leader')) {
                            return $this->handleSupervisorRole($user, $group, $role, $arabicRole);
                        }
                        return $this->jsonResponseWithoutMessage("قم بترقية العضو لقائدأولاً", 'data', 200);
                    }
                    ########## Handel Admin, Consultant, Advisor, Marathon_verification_supervisor, Marathon_coordinator Roles ##########

                    if (in_array($role->name, ['admin', 'consultant', 'advisor', 'marathon_verification_supervisor', 'marathon_coordinator', 'special_care_coordinator'])) {
                        return  $this->handelAdministrationRoles($user, $group, $role, $arabicRole);
                    }
                } else {
                    return $this->jsonResponseWithoutMessage("قم بترقية العضو ل" . $arabicRole . " أولاً", 'data', 200);
                }
            } else {
                return $this->jsonResponseWithoutMessage("المجموعة غير موجودة", 'data', 200);
            }
        } else {
            return $this->jsonResponseWithoutMessage("المستخدم غير موجود", 'data', 200);
        }
    }



    public function notifyAddToGroup($user, $group, $arabicRole)
    {
        $msg = "تمت إضافتك ك " . $arabicRole . " في المجموعة:  " . $group->name;
        (new NotificationController)->sendNotification($user->id, $msg, ROLES, $this->getGroupPath($group->id));

        //notify by email
        //$user->notify((new MailMemberAdd($arabicRole, $group))->delay(now()->addMinutes(2)));
        $successMessage = 'تمت إضافة العضو ك' . $arabicRole . " للمجموعة";

        $logInfo = ' قام ' . Auth::user()->name . " باضافة " . $user->name . ' إلى فريق ' . $group->name . ' بدور '  . $arabicRole;
        Log::channel('community_edits')->info($logInfo);

        return $this->jsonResponseWithoutMessage($successMessage, 'data', 200);
    }

    public function  handleAmbassadorRole($user, $group, $role, $arabicRole)
    {

        //CHECK GROUP TYPE
        if ($group->type->type == 'followup' || $group->type->type == 'advanced_followup' || $group->type->type == 'sophisticated_followup') {
            //CHECK IF LEADER EXISTS

            if ($group->groupLeader->isEmpty()) {
                return $this->jsonResponseWithoutMessage("لا يوجد قائد للمجموعة, لا يمكنك إضافة أعضاء", 'data', 200);
            } else {

                //CHECK GROUP TYPE
                if (in_array($group->type->type, ['advanced_followup', 'sophisticated_followup'])) {

                    UserGroup::where('user_type', 'ambassador')->where('user_id', $user->id)->update(['termination_reason'  => 'upgradet_to_' . $group->type->type]);
                } else {
                    //CHECK IF USER IS AMBASSADOR IN ANOTHER GROUP
                    if (UserGroup::where('user_id', $user->id)->where('user_type', 'ambassador')->whereNull('termination_reason')->exists()) {
                        return $this->jsonResponseWithoutMessage("العضو موجود كـسفير في مجموعة أخرى", 'data', 200);
                    }
                }

                UserGroup::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'group_id' => $group->id,
                        'user_type' => $role->name,
                        'termination_reason' => null
                    ],
                    [
                        'user_id' => $user->id,
                        'group_id' => $group->id,
                        'user_type' => $role->name,
                        'termination_reason' => null
                    ]
                );

                // if user is not admin|consultant|advisor make leader parent
                if (!$user->hasanyrole('admin|consultant|advisor|supervisor|leader')) {
                    $user->parent_id = $group->groupLeader[0]->id;
                    $user->save();
                    $user->notify(new MailAmbassadorDistribution($group->id));
                }
            }
        } else {
            UserGroup::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'group_id' => $group->id,
                    'user_type' => $role->name,
                    'termination_reason' => null
                ],
                [
                    'user_id' => $user->id,
                    'group_id' => $group->id,
                    'user_type' => $role->name,
                    'termination_reason' => null
                ]
            );
        }

        return $this->notifyAddToGroup($user, $group, $arabicRole);
    }

    public function  handleMarathonAmbassadorRole($user, $group, $role, $arabicRole)
    {
        //CHECK IF USER IS MARATHON AMBASSADOR IN ANOTHER GROUP
        if (UserGroup::where('user_id', $user->id)->where('user_type', 'marathon_ambassador')->whereNull('termination_reason')->exists()) {
            return $this->jsonResponseWithoutMessage("العضو موجود  كـ سفير مشارك في مجموعة أخرى", 'data', 200);
        } else {
            UserGroup::Create(
                [
                    'user_id' => $user->id,
                    'group_id' => $group->id,
                    'user_type' => $role->name
                ]
            );
        }

        return $this->notifyAddToGroup($user, $group, $arabicRole);
    }
    public function  handleLeaderRole($user, $group, $role, $arabicRole)
    {

        ########## check if Leader exists in another group as a leader ##########
        $leaderInGroups = UserGroup::where('user_id', $user->id)->where('user_type', $role->name)->where('group_id', '!=', $group->id)->whereNull('termination_reason')->first();
        if ($leaderInGroups) {
            return $this->jsonResponseWithoutMessage("لا يمكنك إضافة هذا العضو ك" . config('constants.ARABIC_ROLES')[$role->name] . ", لأنه موجود ك" . config('constants.ARABIC_ROLES')[$role->name] . " في فريق آخر ", 'data', 200);
        }

        ########## check if Leader exists in group ##########
        $checkMember = UserGroup::where('group_id', $group->id)
            ->where('user_type', $role->name)
            ->whereNull('termination_reason')->first();
        if ($checkMember) {
            return $this->jsonResponseWithoutMessage('يوجد ' . $arabicRole .  ' في المجموعة ', 'data', 200);
        }
        if (!in_array($group->type->type, ['followup', 'advanced_followup', 'sophisticated_followup']) && in_array($role->name, ['leader', 'support_leader'])) {
            return $this->jsonResponseWithoutMessage(config('constants.ARABIC_ROLES')[$role->name] . " يُضاف بهذا الدور في أفرقة المتابعة فقط ", 'data', 200);
        }

        ########## check if Leader is a supervisor ##########
        //supervisor is leader and supervisor in his followup team
        if ($user->hasRole('supervisor') && $role->name === 'leader' && $group->type->type === 'followup') {
            $rolesToAdd = [
                [
                    'user_id' => $user->id,
                    'group_id' => $group->id,
                    'user_type' => 'leader',
                    'created_at' => now(),
                    'updated_at' => now()
                ],
            ];

            //check if the user is added a supervisor before
            $foundAsSupervisor = UserGroup::where('user_id', $user->id)->where('group_id', $group->id)->where('user_type', 'supervisor')->first();
            if (!$foundAsSupervisor) {
                array_push($rolesToAdd,  [
                    'user_id' => $user->id,
                    'group_id' => $group->id,
                    'user_type' => 'supervisor',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            UserGroup::insert($rolesToAdd);
            //notify the user with the supervisor addition
            if (!$foundAsSupervisor) {
                $arabicRole = $arabicRole . ' ومراقب';
            }
        } else {
            UserGroup::Create(
                [
                    'user_id' => $user->id,
                    'group_id' => $group->id,
                    'user_type' => $role->name
                ]
            );
        }
        // Make sure the leader is parent of group ambassadors
        if (in_array($role->name, ['leader', 'special_care_leader']) && in_array($group->type->type, ['followup', 'advanced_followup', 'sophisticated_followup', 'special_care'])) {
            $leaderID = $user->id;
            $groupAmbassadors = $group->userAmbassador->pluck('id');
            foreach ($groupAmbassadors as $ambassadorID) {
                User::where('id', $ambassadorID)->update(['parent_id' => $leaderID]);
            }
        }
        return $this->notifyAddToGroup($user, $group, $arabicRole);
    }

    public function  handleSupervisorRole($user, $group, $role, $arabicRole)
    {

        ########## check if Supervisor exists in group ##########
        $checkMember = UserGroup::where('group_id', $group->id)
            ->where('user_type', $role->name)
            ->whereNull('termination_reason')->first();
        if ($checkMember) {
            return $this->jsonResponseWithoutMessage('يوجد ' . $arabicRole .  ' في المجموعة ', 'data', 200);
        } else {
            UserGroup::Create(
                [
                    'user_id' => $user->id,
                    'group_id' => $group->id,
                    'user_type' => $role->name
                ]
            );
        }

        // Make sure the supervisor is parent of group ambassadors [leaders]
        if ($group->type->type === 'supervising') {
            $supervisorID = $user->id;
            $groupAmbassadors = $group->userAmbassador->pluck('id');
            foreach ($groupAmbassadors as $ambassadorID) {
                User::where('id', $ambassadorID)->update(['parent_id' => $supervisorID]);
            }
        }
        ########## Add Supervisor to leaders groups as asupervisor ##########

        return $this->notifyAddToGroup($user, $group, $arabicRole);
    }

    public function  handelAdministrationRoles($user, $group, $role, $arabicRole)
    {

        ########## check if administrator exists in group ##########
        if ($group->type->type === 'advising' || $group->type->type === 'supervising' || $group->type->type === 'followup' ||  $group->type->type === 'marathon') {
            $checkMember = UserGroup::where('group_id', $group->id)
                ->where('user_type', $role->name)
                ->whereNull('termination_reason')->first();
            if ($checkMember) {
                return $this->jsonResponseWithoutMessage('يوجد ' . $arabicRole .  ' في المجموعة ', 'data', 200);
            }
        }
        UserGroup::Create(
            [
                'user_id' => $user->id,
                'group_id' => $group->id,
                'user_type' => $role->name
            ]
        );

        return $this->notifyAddToGroup($user, $group, $arabicRole);
    }

    public function assign_role(Request $request)
    {
        #####Asmaa####

        $validator = Validator::make(
            $request->all(),
            [
                'group_id' => 'required',
                'user_id' => [
                    'required',
                    Rule::unique('user_groups')->where(fn ($query) => $query->where('group_id', $request->group_id))
                ],
                'user_type' => 'required',
            ]
        );

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->can('assign role')) {
            $user = User::find($request->user_id);
            $role = Role::where('name', $request->user_type)->first();
            $group = Group::where('id', $request->group_id)->first();

            if ($user && $role && $group) {
                $user->assignRole($role);

                $msg = "Now, you are " . $role->name . " in " . $group->name . " group";
                (new NotificationController)->sendNotification($request->user_id, $msg, ROLES, $this->getGroupPath($group->id));

                $userGroup = UserGroup::create($request->all());

                return $this->jsonResponse(new UserGroupResource($userGroup), 'data', 200, 'User Group Created Successfully');
            } else {
                throw new NotFound;
            }
        } else {
            throw new NotAuthorized;
        }
    }
    /**
     * remove role to specific user with create group to him/her.
     * after that,this user will receive a new notification about termination reason(update role” permission is required).
     *
     * @param  Request  $request
     * @return jsonResponseWithoutMessage;
     */
    public function update_role(Request $request)
    {
        #####Asmaa####
        $validator = Validator::make(
            $request->all(),
            [
                'group_id' => 'required',
                'user_type' => 'required',
                'user_group_id' => 'required',
                'termination_reason' => 'required',
                'user_id' => [
                    'required',
                    Rule::unique('user_groups')->where(fn ($query) => $query->where('group_id', $request->group_id))->ignore(request('user_id'), 'user_id')
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        $userGroup = UserGroup::find($request->user_group_id);

        if ($userGroup) {
            if (Auth::user()->can('update role')) {

                $user = User::find($request->user_id);
                $role = Role::where('name', $request->user_type)->first();
                $group = Group::where('id', $request->group_id)->first();

                if ($user && $role && $group) {
                    $user->removeRole($role);

                    $msg = "You are not a " . $role->name . " in " . $group->name . " group anymore, because you " . $request->termination_reason;
                    (new NotificationController)->sendNotification($request->user_id, $msg, ROLES, $this->getGroupPath($group->id));

                    $userGroup->update($request->all());

                    return $this->jsonResponse(new UserGroupResource($userGroup), 'data', 200, 'User Group Updated Successfully');
                } else {
                    throw new NotFound;
                }
            } else {
                throw new NotAuthorized;
            }
        } else {
            throw new NotFound;
        }
    }
    /**
     * Read all user groups by its id in the system.
     *
     * @param  Request  $request
     * @return jsonResponseWithoutMessage;
     */
    public function list_user_group(Request $request)
    {
        #####Asmaa####

        $validator = Validator::make(
            $request->all(),
            [
                'user_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        $userGroups = UserGroup::where('user_id', $request->user_id)->get();

        if ($userGroups) {
            return $this->jsonResponseWithoutMessage(UserGroupResource::collection($userGroups), 'data', 200);
        } else {
            throw new NotFound;
        }
    }

    public function delete($user_group_id)
    {
        if (Auth::user()->hasanyrole('admin|consultant|advisor')) {
            $user_group = UserGroup::find($user_group_id);
            if ($user_group) {
                $logInfo = ' قام ' . Auth::user()->name . " بحذف السفير " . $user_group->user->name . ' من فريق ' . $user_group->group->name;
                //asmaa - check if the deleted member is support_leader then remove the support_leader of the user
                if ($user_group->user_type == 'support_leader') {
                    $user = User::find($user_group->user_id);
                    // $user->removeRole('support_leader');
                    $logInfo = ' قام ' . Auth::user()->name . " بحذف قائد الدعم " . $user_group->user->name . ' من فريق ' . $user_group->group->name;
                }

                $user_group->delete();
                Log::channel('community_edits')->info($logInfo);

                return $this->jsonResponseWithoutMessage('User Deleted', 'data', 200);
            } else {
                throw new NotFound();
            }
        }
        //endif Auth

        else {
            throw new NotAuthorized;
        }
    }


    public function withdrawnMember(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'user_group_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {
            $userGroup = UserGroup::find($request->user_group_id);

            if ($userGroup) {

                $userToWithdrawn = User::find($userGroup->user_id);

                if ($userToWithdrawn->hasanyrole('admin|advisor|consultant|supervisor|leader')) {
                    return $this->jsonResponseWithoutMessage('Withdrawn NOT Allowed', 'data', 200);
                }

                $previous_week = Week::orderBy('created_at', 'desc')->skip(1)->take(2)->first();
                $current_week = Week::orderBy('created_at', 'desc')->first();

                $withdrawingDate = $previous_week->created_at;

                //check user mark for the last week
                $lastWeekMark = Mark::where('week_id', $previous_week->id)
                    ->where('user_id', $userGroup->user_id)
                    ->select(
                        DB::raw('sum(reading_mark + writing_mark + support) as out_of_100'),
                    )
                    ->first();

                //set date to withdran [if last week mark >= 80 withdrawing ambassador current week, else withdrawing ambassador previous week]
                if ($lastWeekMark && $lastWeekMark->out_of_100 >= 80) {
                    $withdrawingDate = $current_week->created_at;
                }

                User::where('id', $userGroup->user_id)->update(['is_hold' => 1, 'parent_id' => null]);
                //Update User Parent
                UserParent::where("user_id", $userGroup->user_id)->update(["is_active" => 0, "updated_at" => $withdrawingDate]);

                $userGroup->termination_reason = 'withdrawn';
                $userGroup->updated_at = $withdrawingDate;
                $userGroup->save();
                $logInfo = ' قام ' . Auth::user()->name . " بسحب السفير " . $userGroup->user->name . ' من فريق ' . $userGroup->group->name;
                Log::channel('community_edits')->info($logInfo);

                return $this->jsonResponseWithoutMessage('User withdrawn', 'data', 200);
            } else {
                throw new NotFound;
            }
        } else {
            throw new NotAuthorized;
        }
    }

    public function membersByMonth($group_id, $month_filter)
    {
        switch ($month_filter) {
            case 'previous':
                $date = Carbon::now()->subMonth();
                break;
            default:
                $date = Carbon::now();
        }

        $members = UserGroup::with('user')->where('group_id', $group_id)
            ->whereYear('created_at', $date->year)
            ->whereMonth('created_at', $date->month)
            ->whereNull('termination_reason')
            ->paginate(10);
        if ($members->isNotEmpty()) {
            return $this->jsonResponseWithoutMessage([
                'members' => $members,
                'total' => $members->total(),
                'last_page' => $members->lastPage(),
            ], 'data', 200);
        }
        return $this->jsonResponseWithoutMessage(null, 'data', 200);
    }
}
