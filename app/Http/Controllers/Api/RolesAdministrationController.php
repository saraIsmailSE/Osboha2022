<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ResponseJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Exceptions\NotAuthorized;
use App\Exceptions\NotFound;
use App\Models\Group;
use App\Models\GroupType;
use App\Models\User;
use App\Models\UserGroup;
use App\Notifications\MailDowngradeRole;
use App\Notifications\MailUpgradeRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RolesAdministrationController extends Controller
{
    use ResponseJson;


    public function getEligibleRoles()
    {
        $rolesToRetrieve = [
            'eligible_admin',
            'reviewer',
            'auditor',
            'super_auditer',
            'super_reviewer',
            'user_accept',
        ];
        $roles = Role::whereIn('name', $rolesToRetrieve)->orderBy('id', 'desc')->get();
        return $this->jsonResponseWithoutMessage($roles, 'data', 200);
    }


    public function getMarathonRoles()
    {
        $rolesToRetrieve = [
            'marathon_coordinator',
            'marathon_verification_supervisor',
            'marathon_supervisor',
            'marathon_ambassador',
        ];
        $roles = Role::whereIn('name', $rolesToRetrieve)->orderBy('id', 'desc')->get();
        return $this->jsonResponseWithoutMessage($roles, 'data', 200);
    }


    public function getRamadanRoles()
    {
        $rolesToRetrieve = [
            'ramadan_coordinator',
            'ramadan_hadith_corrector',
            'ramadan_fiqh_corrector',
            'ramadan_tafseer_corrector',
            'ramadan_vedio_corrector',
        ];
        $roles = Role::whereIn('name', $rolesToRetrieve)->orderBy('id', 'desc')->get();
        return $this->jsonResponseWithoutMessage($roles, 'data', 200);
    }



    public function assignRoleV2(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user' => 'required|email',
            'role_id' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        //check role exists
        $role = Role::find($request->role_id);
        if (!$role) {
            return $this->jsonResponseWithoutMessage("هذه الترقية غير موجودة", 'data', 200);
        }

        //check user exists
        $user = User::where('email', $request->user)->first();
        if ($user) {
            //check if user has the role

            if ($user->hasRole($role->name)) {
                return $this->jsonResponseWithoutMessage("المستخدم موجود مسبقاً ك" . config('constants.ARABIC_ROLES')[$role->name], 'data', 200);
            } else {
                $user->assignRole($role->name);
                $msg = "قام " . Auth::user()->name . " بـ تعيينك : " . config('constants.ARABIC_ROLES')[$role->name];
                (new NotificationController)->sendNotification($user->id, $msg, ROLES);

                $logInfo = ' قام ' . Auth::user()->name . " بترقية العضو " . $user->name . ' لـ ' . config('constants.ARABIC_ROLES')[$role->name];
                Log::channel('community_edits')->info($logInfo);

                return $this->jsonResponseWithoutMessage("تمت ترقية العضو ل " . config('constants.ARABIC_ROLES')[$role->name], 'data', 200);
            }
        } else {
            return $this->jsonResponseWithoutMessage("المستخدم غير موجود", 'data', 200);
        }
    }

    /**
     * assign role and assign head user to a user .
     *
     * @return jsonResponseWithoutMessage;
     */

    public function assignRole(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user' => 'required|email',
            'head_user' => 'required_unless:role_id,8|email|nullable',
            'role_id' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        //check role exists
        $role = Role::find($request->role_id);
        if (!$role) {
            return $this->jsonResponseWithoutMessage("هذه الرتبة غير موجودة", 'data', 200);
        }

        //check user exists
        $user = User::where('email', $request->user)->first();
        if ($user) {
            //check if role is support leader, just assign role

            if ($role->name === 'support_leader') {
                if (Auth::user()->hasAnyRole(config('constants.ALL_SUPPER_ROLES'))) {

                    if ($user->hasAnyRole(config('constants.ALL_SUPPER_ROLES'))) {
                        return $this->jsonResponseWithoutMessage("لا يمكنك ترقية المستخدم ل " . config('constants.ARABIC_ROLES')[$role->name] . " لأنه يملك صلاحيات قيادية", 'data', 200);
                    }

                    if ($user->hasRole($role->name)) {
                        return $this->jsonResponseWithoutMessage("المستخدم موجود مسبقاً ك" . config('constants.ARABIC_ROLES')[$role->name], 'data', 200);
                    }

                    $user->assignRole($role->name);

                    $logInfo = ' قام ' . Auth::user()->name . " بترقية العضو " . $user->name . ' لـ ' . config('constants.ARABIC_ROLES')[$role->name];
                    Log::channel('community_edits')->info($logInfo);

                    return $this->jsonResponseWithoutMessage("تمت ترقية العضو ل " . config('constants.ARABIC_ROLES')[$role->name], 'data', 200);
                } else {
                    return $this->jsonResponseWithoutMessage("ليس لديك صلاحية لترقية العضو ل " . config('constants.ARABIC_ROLES')[$role->name], 'data', 200);
                }
            }

            //check head_user exists
            $head_user = User::where('email', $request->head_user)->first();
            if ($head_user) {
                $head_user_last_role = $head_user->roles->first();
                //check if head user role is greater that user role
                if ($head_user_last_role->id < $role->id) {
                    $user_current_role = $user->roles->first();
                    $arabicRole = config('constants.ARABIC_ROLES')[$role->name];
                    $userRoles = null;

                    //check if supervisor is a leader first
                    if (($user_current_role->name === 'ambassador' && $role->name === 'supervisor') ||
                        ($user_current_role->name === 'advisor' && $role->name === 'supervisor') ||
                        ($user_current_role->name === 'consultant' && $role->name === 'supervisor')
                    ) {
                        return $this->jsonResponseWithoutMessage("لا يمكنك ترقية العضو لمراقب مباشرة, يجب أن يكون قائد أولاً", 'data', 200);
                    }

                    //check if user has the role
                    if ($user->hasRole($role->name) && $user_current_role->id >= $role->id) {
                        return $this->jsonResponseWithoutMessage("المستخدم موجود مسبقاً ك" . $arabicRole, 'data', 200);
                    }

                    //if the last role of the user is greater than the new role (last role is less than new role) => downgrade
                    if ($user_current_role->id > $role->id) {

                        //remove last role if not ambassador or leader and new role is supervisor
                        if ($user_current_role->name !== 'ambassador' && !($user_current_role->name === 'leader' && $role->name === 'supervisor')) {
                            $user->removeRole($user_current_role->name);
                        }

                        //else remove all roles except the ambassador (upgrade)
                    } else {
                        $userRoles = $user->roles()->where('name', '!=', 'ambassador')->pluck('name');
                        foreach ($userRoles as $userRole) {
                            $user->removeRole($userRole);
                        }

                        $userRoles = collect($userRoles)->map(function ($role) {
                            return config('constants.ARABIC_ROLES')[$role];
                        });
                    }

                    // assign new role
                    $user->assignRole($role->name);

                    // Link with head user
                    $user->parent_id = $head_user->id;
                    $user->save();

                    $msg = "";
                    $successMessage = "";
                    if (!$userRoles) {
                        $msg = "تمت ترقيتك ل " . $arabicRole . " - المسؤول عنك:  " . $head_user->name;
                        $successMessage = "تمت ترقية العضو ل " . $arabicRole . " - المسؤول عنه:  " . $head_user->name;
                        $user->notify(new MailUpgradeRole($arabicRole));
                    } else {
                        $msg = count($userRoles) > 1
                            ?
                            "تم سحب الأدوار التالية منك: " . implode(',', $userRoles->all()) . " أنت الآن " . $arabicRole
                            :
                            "تم سحب دور ال" . $userRoles[0] . " منك, أنت الآن " . $arabicRole;
                        $successMessage = count($userRoles) > 1
                            ?
                            "تم سحب الأدوار التالية من العضو: " . implode(',', $userRoles->all()) . " , إنه الآن " . $arabicRole
                            :
                            "تم سحب دور ال" . $userRoles[0] . " من العضو, إنه الآن " . $arabicRole;
                        $user->notify(new MailDowngradeRole($userRoles->all(), $arabicRole));
                    }
                    // notify user
                    (new NotificationController)->sendNotification($user->id, $msg, ROLES);

                    $logInfo = ' قام ' . Auth::user()->name . " بترقية العضو " . $user->name . ' لـ ' . $arabicRole . " - المسؤول عنه:  " . $head_user->name;
                    Log::channel('community_edits')->info($logInfo);

                    return $this->jsonResponseWithoutMessage($successMessage, 'data', 200);
                } else {
                    return $this->jsonResponseWithoutMessage("يجب أن تكون رتبة المسؤول أعلى من الرتبة المراد الترقية لها", 'data', 200);
                }
            } else {
                return $this->jsonResponseWithoutMessage("المسؤول غير موجود", 'data', 200);
            }
        } else {
            return $this->jsonResponseWithoutMessage("المستخدم غير موجود", 'data', 200);
        }
    }

    /**
     * Change advising team for a supervisor
     *
     * @return jsonResponseWithoutMessage;
     */

    public function ChangeAdvisingTeam(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supervisor' => 'required|email',
            'new_advisor' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        //get supervisor info
        $supervisor = User::where('email', $request->supervisor)->first();
        if ($supervisor) {
            //check if supervisor has supervisor role
            if ($supervisor->hasRole('supervisor')) {
                // get new advisor info
                $new_advisor = User::where('email', $request->new_advisor)->first();
                //check if new_advisor has advisor role

                if ($new_advisor->hasRole('advisor')) {
                    DB::beginTransaction();
                    try {

                        // link supervisor with his new advisor
                        $supervisor->parent_id = $new_advisor->id;
                        $supervisor->save();

                        /* make supervisor ambassador in new advisor advising group
                        * 1- get advising group
                        * 2- update group_id where supervisor is ambassador
                        */

                        // 1- get advising group
                        $advising_group = UserGroup::where('user_id', $new_advisor->id)->where('user_type', 'advisor')->whereNull('termination_reason')->whereHas('group.type', function ($q) {
                            $q->where('type', 'advising');
                        })->first();

                        if ($advising_group) {
                            // 2- update group_id where supervisor is ambassador
                            $user_group = UserGroup::updateOrCreate(
                                [
                                    'user_id' => $supervisor->id,
                                    'user_type' => 'ambassador',
                                ],
                                [
                                    'user_id' => $supervisor->id,
                                    'group_id' => $advising_group->group_id,
                                    'user_type' => 'ambassador',
                                ]
                            );
                        } else {
                            return $this->jsonResponseWithoutMessage("الموجه ليس موجهاً في أي مجموعة", 'data', 200);
                        }

                        /* add new advisor to supervisor`s groups and remove the old advisor
                        * 1- get supervisor`s groups
                        * 2- update user_id where user_type is advisor
                        */

                        //1- get supervisor`s groups
                        $groupsID = UserGroup::where('user_id', $supervisor->id)->where('user_type', 'supervisor')->pluck('group_id');

                        // 2- update user_id where user_type is advisor
                        foreach ($groupsID as $key => $id) {
                            UserGroup::updateOrCreate(
                                [
                                    'group_id' => $id,
                                    'user_type' => 'advisor',
                                ],
                                [
                                    'user_id' => $new_advisor->id,
                                    'group_id' => $id,
                                    'user_type' => 'advisor',
                                ]
                            );
                        }

                        // add supervisor as ambassador to the new advising team
                        UserGroup::where('user_type', 'ambassador')->where('user_id', $supervisor->id)->update(['termination_reason'  => 'advisor_change']);
                        UserGroup::Create(
                            [
                                'user_id' =>  $supervisor->id,
                                'group_id' => $advising_group->group_id,
                                'user_type' => 'ambassador'
                            ]
                        );

                        DB::commit();

                        $logInfo = ' قام ' . Auth::user()->name . " بنقل " . $supervisor->name . ' لـ ' .  $new_advisor->name;
                        Log::channel('community_edits')->info($logInfo);

                        return $this->jsonResponseWithoutMessage("تم التبديل", 'data', 200);
                    } catch (\Exception $e) {
                        Log::channel('RolesAdministration')->info($e);
                        DB::rollBack();

                        return $e->getMessage();
                    }
                } else {
                    return $this->jsonResponseWithoutMessage("لا يمكنك نقل العضو لموجه أخر , يجب أن يكون الموجه موجهاً أولاً", 'data', 200);
                }
            } else {
                return $this->jsonResponseWithoutMessage("لا يمكنك نقل العضو لموجه أخر , يجب أن يكون مراقباً أولاً", 'data', 200);
            }
        } else {
            return $this->jsonResponseWithoutMessage("المراقب غير موجود", 'data', 200);
        }
    }

    /**
     * Swap Leaders Between 2 Supervisors
     * @param Request contains currentSupervisor email, newSupervisor email
     * @return jsonResponseWithoutMessage;
     */

    public function supervisorsSwap(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currentSupervisor' => 'required|email',
            'newSupervisor' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {
            //get supervisors info
            $currentSupervisor = User::where('email', $request->currentSupervisor)->first();
            $newSupervisor = User::where('email', $request->newSupervisor)->first();

            if ($currentSupervisor && $newSupervisor) {
                //check if supervisor has supervisor role
                if ($currentSupervisor->hasRole('supervisor') && $newSupervisor->hasRole('supervisor')) {

                    //supervising group and its leaders [leaders here are ambassadors] for  supervisor 1

                    try {
                        DB::beginTransaction();


                        ########## check Advisors ##########

                        //if 2 diffrent advisors
                        if ($currentSupervisor->parent_id  != $newSupervisor->parent_id) {
                            $supervisors_1_Parent = $currentSupervisor->parent_id;
                            $supervisors_1_Parent_advising_group = Group::whereHas('type', function ($q) {
                                $q->where('type', 'advising');
                            })
                                ->whereHas('users', function ($q) use ($supervisors_1_Parent) {
                                    $q->where('user_id', $supervisors_1_Parent);
                                })
                                ->first();

                            $supervisors_2_Parent = $newSupervisor->parent_id;
                            $supervisors_2_Parent_advising_group = Group::whereHas('type', function ($q) {
                                $q->where('type', 'advising');
                            })
                                ->whereHas('users', function ($q) use ($supervisors_2_Parent) {
                                    $q->where('user_id', $supervisors_2_Parent);
                                })
                                ->first();


                            //change parent id for each supervisor and add advisors to groups

                            $currentSupervisor->parent_id =  $supervisors_2_Parent;
                            $currentSupervisor->save();
                            $currentSupervisorGroups = UserGroup::where('user_type', 'supervisor')->where('user_id', $currentSupervisor->id)->pluck('group_id');


                            //add advisor to groups
                            UserGroup::where('user_type', 'advisor')->whereIn('group_id', $currentSupervisorGroups)->update(['user_id'  => $supervisors_2_Parent]);
                            //add supervisor as ambassador to advising group
                            UserGroup::where('user_type', 'ambassador')->where('user_id', $currentSupervisor->id)->update(['group_id'  => $supervisors_2_Parent_advising_group->id]);

                            $newSupervisor->parent_id =  $supervisors_1_Parent;
                            $newSupervisor->save();
                            $newSupervisorGroups = UserGroup::where('user_type', 'supervisor')->where('user_id', $newSupervisor->id)->pluck('group_id');
                            UserGroup::where('user_type', 'advisor')->whereIn('group_id', $newSupervisorGroups)->update(['user_id'  => $supervisors_1_Parent]);
                            UserGroup::where('user_type', 'ambassador')->where('user_id', $newSupervisor->id)->update(['group_id'  => $supervisors_1_Parent_advising_group->id]);
                        }

                        $supervising1Group = Group::whereHas('type', function ($q) {
                            $q->where('type', 'supervising');
                        })
                            ->whereHas('users', function ($q) use ($currentSupervisor) {
                                $q->where('user_id', $currentSupervisor->id);
                            })
                            ->with('userAmbassador')
                            ->first();

                        $supervising2Group = Group::whereHas('type', function ($q) {
                            $q->where('type', 'supervising');
                        })
                            ->whereHas('users', function ($q) use ($newSupervisor) {
                                $q->where('user_id', $newSupervisor->id);
                            })
                            ->with('userAmbassador')
                            ->first();

                        if ($supervising1Group) {
                            foreach ($supervising1Group->userAmbassador as $leader) {
                                // for each leader in group 1
                                //update supervisor
                                $leader->update(['parent_id'  => $newSupervisor->id]);
                                $follow_up_group_id = UserGroup::where('user_type', 'leader')->where('user_id', $leader->id)->pluck('group_id')->first();
                                UserGroup::where('user_type', 'supervisor')->where('group_id', $follow_up_group_id)->update(['user_id'  => $newSupervisor->id]);
                            }
                        } else {
                            return $this->jsonResponseWithoutMessage("المراقب الأول ليس مراقباً في مجموعة الرقابة ", 'data', 200);
                        }

                        if ($supervising2Group) {
                            foreach ($supervising2Group->userAmbassador as $leader) {
                                // for each leader in group 1
                                //update supervisor
                                $leader->update(['parent_id'  => $currentSupervisor->id]);
                                //update supervisor in following up leader group
                                $follow_up_group_id = UserGroup::where('user_type', 'leader')->where('user_id', $leader->id)->pluck('group_id')->first();
                                UserGroup::where('user_type', 'supervisor')->where('group_id', $follow_up_group_id)->update(['user_id'  => $currentSupervisor->id]);
                            }
                        } else {
                            return $this->jsonResponseWithoutMessage("المراقب الثاني ليس مراقباً في مجموعة الرقابة ", 'data', 200);
                        }


                        ########## Change Supervising Team ##########

                        //terminate current recored then create one
                        UserGroup::where('user_type', 'supervisor')->where('user_id', $currentSupervisor->id)->where('group_id', $supervising1Group->id)->update(['termination_reason'  => 'supervisor_change']);
                        UserGroup::Create(
                            [
                                'user_id' =>  $currentSupervisor->id,
                                'group_id' => $supervising2Group->id,
                                'user_type' => 'supervisor'
                            ]
                        );
                        UserGroup::where('user_type', 'supervisor')->where('user_id', $newSupervisor->id)->where('group_id', $supervising2Group->id)->update(['termination_reason'  => 'supervisor_change']);
                        UserGroup::Create(
                            [
                                'user_id' =>  $newSupervisor->id,
                                'group_id' => $supervising1Group->id,
                                'user_type' => 'supervisor'
                            ]
                        );

                        DB::commit();

                        $logInfo = ' قام ' . Auth::user()->name . " بالتبديل " . $currentSupervisor->name . ' و ' .  $newSupervisor->name;
                        Log::channel('community_edits')->info($logInfo);

                        return $this->jsonResponseWithoutMessage("تم التبديل", 'data', 200);
                    } catch (\Exception $exception) {
                        Log::channel('RolesAdministration')->info($exception);
                        DB::rollBack();
                    }
                } else {
                    return $this->jsonResponseWithoutMessage("يجب أن يكون العضو مراقب أولاً", 'data', 200);
                }
            } else {
                return $this->jsonResponseWithoutMessage("المراقب غير موجود", 'data', 200);
            }
        } else {
            throw new NotAuthorized;
        }
    }



    /**
     * set new supervisor and let the current Ambassador only
     * @param Request contains currentSupervisor email, newSupervisor email
     * @return jsonResponseWithoutMessage;
     */

    public function newSupervisor_currentToAmbassador(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currentSupervisor' => 'required|email',
            'newSupervisor' => 'required|email',
            'newLeader'  => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }


        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {

            $currentSupervisor = User::where('email', $request->currentSupervisor)->first();
            if ($currentSupervisor) {
                //get new supervisor info
                $newSupervisor = User::where('email', $request->newSupervisor)->first();
                if ($newSupervisor) {
                    //check if new supervisor has supervisor role
                    if ($newSupervisor->hasRole('supervisor')) {
                        //get new leader info
                        $newLeader = User::where('email', $request->newLeader)->first();

                        if ($newLeader) {
                            //check if new leader has leader role
                            if ($newLeader->hasRole('leader')) {

                                //get current supervisor followup group [where he is a leader]
                                $currentSupervisor_leadingGroup = UserGroup::where('user_id', $currentSupervisor->id)
                                    ->where('user_type', 'leader')
                                    ->whereNull('termination_reason')
                                    ->first();
                                if ($currentSupervisor_leadingGroup) {

                                    //get current supervisor followup group [where he is an ambassador [ADVISING GROUP] ]
                                    $currentSupervisor_followupGroup = UserGroup::where('user_id', $currentSupervisor->id)
                                        ->where('user_type', 'ambassador')
                                        ->whereNull('termination_reason')
                                        ->first();
                                    DB::beginTransaction();
                                    try {

                                        //* اضافة المراقب الجديد إلى مجموعة التوجيه كـ سفير

                                        UserGroup::updateOrCreate(
                                            [
                                                'user_id' => $newSupervisor->id,
                                                'user_type' => "ambassador"
                                            ],
                                            [
                                                'group_id' => $currentSupervisor_followupGroup->group_id
                                            ]
                                        );

                                        //* نقل قادة المراقب الحالي إلى المراقب الجديد
                                        User::where("parent_id", $currentSupervisor->id)->whereHas('roles', function ($q) {
                                            $q->where('name', '=', 'leader');
                                        })->update(["parent_id" => $newSupervisor->id]);

                                        //* نقل سفراء المراقب الحالي إلى القائد الجديد
                                        User::where("parent_id", $currentSupervisor->id)->whereHas('roles', function ($q) {
                                            $q->whereNotIn('name', ['leader', 'supervisor', 'admin', 'advisor', 'consultant']);
                                        })->update(["parent_id" => $newLeader->id]);


                                        //* المسؤول عن المراقب الحالي يصبح القائد الجديد
                                        $currentSupervisor->parent_id = $newLeader->id;
                                        $currentSupervisor->save();

                                        //* سحب رتبة الرقابة والقيادة من المراقب الحالي
                                        $currentSupervisor->removeRole("supervisor");
                                        $currentSupervisor->removeRole("leader");

                                        //* اضافة القائد الجديد إلى مجموعة المتابعة كـ قائد
                                        UserGroup::where('user_type', 'leader')->where('user_id',  $newLeader->id)->update(['termination_reason'  => 'supervisor_change']);
                                        UserGroup::create(
                                            [
                                                'user_id' => $newLeader->id,
                                                'user_type' => "leader",
                                                'group_id' => $currentSupervisor_leadingGroup->group_id
                                            ]
                                        );

                                        //* اضافة القائد الجديد إلى مجموعة الرقابة كـ سفير

                                        //get current supervisor Supervising group [where he is a supervisor]


                                        $currentSupervisor_supervisingGroup = UserGroup::whereHas('group.type', function ($q) {
                                            $q->where('type', '=', 'supervising');
                                        })
                                            ->where('user_groups.user_id', $currentSupervisor->id)
                                            ->where('user_groups.user_type', 'supervisor')
                                            ->whereNull('user_groups.termination_reason')
                                            ->first();

                                        // $currentSupervisor_supervisingGroup = Group::whereHas('type', function ($q) {
                                        //     $q->where('type', '=', 'supervising');
                                        // })
                                        //     ->where('user_groups.user_id', $currentSupervisor->id)
                                        //     ->where('user_groups.user_type', 'supervisor')
                                        //     ->whereNull('user_groups.termination_reason')
                                        //     ->first();

                                        UserGroup::where('user_type', 'ambassador')->where('user_id',  $newLeader->id)->update(['termination_reason'  => 'supervisor_change']);
                                        UserGroup::create(
                                            [
                                                'user_id' => $newLeader->id,
                                                'user_type' => "ambassador",
                                                'group_id' => $currentSupervisor_supervisingGroup->group_id
                                            ]
                                        );

                                        //* اضافة المراقب الجديد إلى مجموعة الرقابة كـ مراقب
                                        //* اضافة المراقب الجديد إلى مجموعات القادة كـ مراقب [بالاضافة إلى مجموعة المتابعة الخاصة بالمراقب الحالي]
                                        UserGroup::where('user_id', $currentSupervisor->id)
                                            ->where('user_type', "supervisor")
                                            ->update(['user_id'  => $newSupervisor->id]);

                                        DB::commit();
                                        $logInfo = ' قام ' . Auth::user()->name . " بالتبديل " . $currentSupervisor->name . ' و ' .  $newSupervisor->name . ' وأصبح ' . $currentSupervisor->name . ' سفيرا ';
                                        Log::channel('community_edits')->info($logInfo);

                                        return $this->jsonResponseWithoutMessage("تم التبديل", 'data', 200);
                                    } catch (\Exception $e) {
                                        Log::channel('RolesAdministration')->info($e);
                                        DB::rollBack();

                                        return $e->getMessage();
                                    }
                                } else {
                                    return $this->jsonResponseWithoutMessage("يجب أن يكون المراقب الحالي قائدًا لفريق متابعة", 'data', 200);
                                }
                            } else {
                                return $this->jsonResponseWithoutMessage("يجب أن يكون القائد الجديد قائدًا أولًا", 'data', 200);
                            }
                        } else {
                            return $this->jsonResponseWithoutMessage("القائد الجديد غير موجود", 'data', 200);
                        }
                    } else {
                        return $this->jsonResponseWithoutMessage("يجب أن يكون المراقب الجديد مراقباً أولاً", 'data', 200);
                    }
                } else {
                    return $this->jsonResponseWithoutMessage("المراقب الجديد غير موجود", 'data', 200);
                }
            } else {
                return $this->jsonResponseWithoutMessage("المراقب الحالي غير موجود", 'data', 200);
            }
        } else {
            throw new NotAuthorized;
        }
    }


    /**
     * set new supervisor and let the current Leader only
     * @param Request contains currentSupervisor email, newSupervisor email
     * @return jsonResponseWithoutMessage;
     */

    public function newSupervisor_currentToLeader(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currentSupervisor' => 'required|email',
            'newSupervisor' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {

            $currentSupervisor = User::where('email', $request->currentSupervisor)->first();
            if ($currentSupervisor) {
                //get new supervisor info
                $newSupervisor = User::where('email', $request->newSupervisor)->first();
                if ($newSupervisor) {

                    $supervisingGroupTypeID = GroupType::where('type', 'supervising')->pluck('id')->first();

                    //check if new supervisor has supervisor role
                    if ($newSupervisor->hasRole('supervisor')) {

                        //get new supervisor followup group [where he is a leader]
                        $newSupervisor_leadingGroup = UserGroup::where('user_id', $newSupervisor->id)
                            ->where('user_type', 'leader')
                            ->whereNull('termination_reason')
                            ->first();
                        if ($newSupervisor_leadingGroup) {

                            //get current supervisor followup group [where he is an ambassador [ADVISING GROUP] ]
                            $currentSupervisor_followupGroup = UserGroup::where('user_id', $currentSupervisor->id)
                                ->where('user_type', 'ambassador')
                                ->whereNull('termination_reason')
                                ->first();
                            DB::beginTransaction();
                            try {

                                //* اضافة المراقب الجديد إلى مجموعة التوجيه كـ سفير
                                UserGroup::where('user_type', 'ambassador')->where('user_id',  $newSupervisor->id)->update(['termination_reason'  => 'supervisor_upgrade']);
                                UserGroup::create(
                                    [
                                        'user_id' => $newSupervisor->id,
                                        'user_type' => "ambassador",
                                        'group_id' => $currentSupervisor_followupGroup->group_id
                                    ]
                                );


                                //* نقل قادة المراقب الحالي إلى المراقب الجديد
                                User::where("parent_id", $currentSupervisor->id)->whereHas('roles', function ($q) {
                                    $q->where('name', '=', 'leader');
                                })->update(["parent_id" => $newSupervisor->id]);
                                //* المسؤول عن المراقب الحالي يصبح المراقب الجديد
                                $currentSupervisor->parent_id = $newSupervisor->id;
                                $currentSupervisor->save();

                                //* سحب رتبة الرقابة من المراقب الحالي
                                $currentSupervisor->removeRole("supervisor");

                                //* اضافة المراقب الجديد إلى مجموعة الرقابة كـ مراقب
                                //* اضافة المراقب الجديد إلى مجموعات القادة كـ مراقب [بالاضافة إلى مجموعة المتابعة الخاصة بالمراقب الحالي]
                                UserGroup::where('user_id', $currentSupervisor->id)
                                    ->where('user_type', "supervisor")
                                    ->update(['user_id'  => $newSupervisor->id]);


                                //* اضافة المراقب الحالي كـ سفير في مجموعة الرقابة الخاصة بالمراقب الجديد
                                $newSupervisor_SupervisingGroupID = UserGroup::where('user_id', $newSupervisor->id)
                                    ->whereHas('group', function ($q)  use ($supervisingGroupTypeID) {
                                        $q->where('type_id', '=', $supervisingGroupTypeID);
                                    })->where('user_type', 'supervisor')->pluck('group_id')->first();

                                UserGroup::where('user_type', 'ambassador')->where('user_id',  $currentSupervisor->id)->update(['termination_reason'  => 'supervisor_withdrawn']);
                                UserGroup::create(
                                    [
                                        'user_id' => $currentSupervisor->id,
                                        'user_type' => "ambassador",
                                        'group_id' => $newSupervisor_SupervisingGroupID
                                    ]
                                );


                                DB::commit();
                                $logInfo = ' قام ' . Auth::user()->name . " بالتبديل " . $currentSupervisor->name . ' و ' .  $newSupervisor->name . ' وأصبح ' . $currentSupervisor->name . ' قائدا ';
                                Log::channel('community_edits')->info($logInfo);

                                return $this->jsonResponseWithoutMessage("تم التبديل", 'data', 200);
                            } catch (\Exception $e) {
                                Log::channel('RolesAdministration')->info($e);
                                DB::rollBack();

                                return $e->getMessage();
                            }
                        } else {
                            return $this->jsonResponseWithoutMessage("يجب أن يكون المراقب الجديد قائدًا لفريق متابعة", 'data', 200);
                        }
                    } else {
                        return $this->jsonResponseWithoutMessage("يجب أن يكون المراقب الجديد مراقباً أولاً", 'data', 200);
                    }
                } else {
                    return $this->jsonResponseWithoutMessage("المراقب الجديد غير موجود", 'data', 200);
                }
            } else {
                return $this->jsonResponseWithoutMessage("المراقب الحالي غير موجود", 'data', 200);
            }
        } else {
            throw new NotAuthorized;
        }
    }



    /**
     *  set new Leader and let the current ambassador only
     * @param Request contains newLeader email, currentLeader email
     * @return jsonResponseWithoutMessage;
     */

    public function newLeader_currentToAmbassador(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'newLeader' => 'required|email',
            'currentLeader' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {
            $currentLeader = User::where('email', $request->currentLeader)->first();

            if ($currentLeader) {
                //get new leader info
                $newLeader = User::where('email', $request->newLeader)->first();

                if ($newLeader) {
                    //check if new leader has leader role
                    if ($newLeader->hasRole('leader')) {

                        //get leader group id
                        $leaderGroupId = UserGroup::where('user_id', $currentLeader->id)
                            ->where('user_type', 'leader')
                            ->pluck('group_id')->first();

                        if ($leaderGroupId) {
                            // نقل سفراء القائد الحالي إلى القائد الجديد
                            DB::beginTransaction();
                            try {

                                //get ambassadors of currentLeader
                                $ambassadorsID = UserGroup::where('group_id', $leaderGroupId)
                                    ->where('user_type', 'ambassador')
                                    ->pluck('user_id');

                                //update parent id for ambassador
                                User::whereIn('id', $ambassadorsID)->update(['parent_id'  => $newLeader->id]);

                                //  إضافة القائد الجديد لغروب الرقابة كسفير
                                $supervisingGroupId = UserGroup::where('user_id', $currentLeader->id)
                                    ->where('user_type', 'ambassador')
                                    ->pluck('group_id')->first();

                                UserGroup::where('user_type', 'ambassador')->where('user_id',  $newLeader->id)->update(['termination_reason'  => 'leader_upgrade']);
                                UserGroup::create(
                                    [
                                        'user_id' => $newLeader->id,
                                        'user_type' => "ambassador",
                                        'group_id' => $supervisingGroupId
                                    ]
                                );

                                // المسؤول عن القائد الجديد هو المراقب
                                User::where('id', $newLeader->id)->update(['parent_id'  => User::where('id', $currentLeader->id)->pluck('parent_id')->first()]);

                                // اضافة القائد الجديد إلى مجموعة المتابعة كقائد
                                UserGroup::where('user_type', 'leader')->where('group_id', $leaderGroupId)->update(['user_id'  => $newLeader->id]);

                                //سحب رتبة القيادة من القائد الحالي
                                $currentLeader->removeRole('leader');

                                //المسؤول عن القائد الحالي هو القائد الجديد
                                User::where('id', $currentLeader->id)->update(['parent_id'  => $newLeader->id]);
                                // اضافة القائد الحالي إلى مجموعة المتابعة الخاصة به كـ سفير
                                UserGroup::where('user_type', 'ambassador')->where('user_id', $currentLeader->id)->update(['group_id'  => $leaderGroupId]);

                                DB::commit();
                                $logInfo = ' قام ' . Auth::user()->name . " بالتبديل " . $currentLeader->name . ' و ' .  $newLeader->name . ' وأصبح ' . $currentLeader->name . ' سفيرا ';
                                Log::channel('community_edits')->info($logInfo);

                                return $this->jsonResponseWithoutMessage(" تم تبديل القائد وجعل القائد القديم سفير في نفس المجموعة", 'data', 200);
                            } catch (\Exception $e) {
                                Log::channel('RolesAdministration')->info($e);
                                DB::rollBack();

                                return $e->getMessage();
                            }
                        } else {
                            return $this->jsonResponseWithoutMessage("يجب أن يكون القائد الحالي قائد لفريق متابعة", 'data', 200);
                        }
                    } else {
                        return $this->jsonResponseWithoutMessage("يجب أن يكون القائد الجديد  له دور قائداَ أولاً", 'data', 200);
                    }
                } else {
                    return $this->jsonResponseWithoutMessage("القائد  الجديد غير موجود", 'data', 200);
                }
            } else {
                return $this->jsonResponseWithoutMessage("القائد  الحالي غير موجود", 'data', 200);
            }
        } else {
            throw new NotAuthorized;
        }
    }
    /**
     * transfer ambassador to new leader
     * @param Request contains ambassador_email, leader_email
     * @return jsonResponseWithoutMessage;
     */

    public function transferAmbassador(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ambassador_email' => 'required|email',
            'leader_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {
            $ambassador = User::where('email', $request->ambassador_email)->first();

            if ($ambassador) {
                //get new leader info
                $newLeader = User::where('email', $request->leader_email)->first();
                if ($newLeader) {
                    // check if new leader has leader role
                    if (!$newLeader->hasRole('leader')) {
                        return $this->jsonResponseWithoutMessage("القائد الجديد لا يحمل رتبة قائد، قم بترقيته أولاً", 'data', 200);
                    }
                    //update parent id for ambassador
                    $ambassador->update(['parent_id'  => $newLeader->id]);
                    //get the followup group of new leader
                    $newLeaderGroupId = UserGroup::where('user_type', 'leader')->where('user_id', $newLeader->id)->pluck('group_id')->first();
                    //transfer ambassador to new followup group
                    UserGroup::where('user_type', 'ambassador')->where('user_id',  $ambassador->id)->update(['termination_reason'  => 'transfer_ambassador']);
                    UserGroup::create(
                        [
                            'user_id' => $ambassador->id,
                            'user_type' => "ambassador",
                            'group_id' => $newLeaderGroupId
                        ]
                    );


                    $logInfo = ' قام ' . Auth::user()->name . " بنقل السفير " . $ambassador->name . ' إلى القائد ' .  $newLeader->name;
                    Log::channel('community_edits')->info($logInfo);

                    return $this->jsonResponseWithoutMessage("تم النقل", 'data', 200);
                } else {
                    return $this->jsonResponseWithoutMessage("حساب القائد غير موجود ", 'data', 200);
                }
            } else {
                return $this->jsonResponseWithoutMessage("حساب السفير غير موجود ", 'data', 200);
            }
        } else {
            throw new NotAuthorized;
        }
    }

    /**
     * move a Leader to newSupervisor
     * @param Request contains leader email [current], newSupervisor email [new]
     * @return jsonResponseWithoutMessage;
     */

    public function transferLeader(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'leaders' => 'required',
            'leaders.*.leader_email' => 'required|email',
            'newSupervisor' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {
            $response = [];
            $response['need_promotion'] = [];
            $response['not_exists'] = [];

            //get new supervisor info
            $newSupervisor = User::where('email', $request->newSupervisor)->first();
            if ($newSupervisor) {
                //check if new supervisor has supervisor role
                if ($newSupervisor->hasRole('supervisor')) {

                    //get supervising group
                    $newSupervisingGroupId = Group::whereHas('type', function ($q) {
                        $q->where('type', 'supervising');
                    })
                        ->whereHas('users', function ($q) use ($newSupervisor) {
                            $q->where('user_id', $newSupervisor->id);
                        })
                        ->pluck('id')
                        ->first();

                    if ($newSupervisingGroupId) {
                        ##### START WORKING WITH LEADERS #####
                        foreach ($request->leaders as $index => $leaderToMove) {
                            $leader = User::where('email', $leaderToMove['leader_email'])->first();
                            if ($leader) {
                                //check if new leader has leader role
                                if ($leader->hasRole('leader')) {

                                    $currentAdvisorId = User::where('id', $leader->parent_id)->pluck('parent_id')->first();

                                    // جعل القائد سفير في غروب الرقابة الجديد
                                    UserGroup::where('user_type', 'ambassador')->where('user_id',  $leader->id)->update(['termination_reason'  => 'transfer_leader']);
                                    UserGroup::create(
                                        [
                                            'user_id' => $leader->id,
                                            'user_type' => "ambassador",
                                            'group_id' => $newSupervisingGroupId
                                        ]
                                    );

                                    //إضافة المراقب الجديد لفريق المتابعة الخاص بالقائد
                                    // find leader group id
                                    $leaderGroupId = UserGroup::where('user_type', 'leader')->where('user_id', $leader->id)->pluck('group_id')->first();
                                    //add new supervisor to leader group
                                    UserGroup::where('user_type', 'supervisor')->where('group_id', $leaderGroupId)->update(['user_id'  => $newSupervisor->id]);

                                    //جعل المراقب الجديد مسؤل عن القائد
                                    $leader->update(['parent_id'  => $newSupervisor->id]);

                                    // تغيير الموجه المسؤول عن القائد إذا كان المراقب الجديد في فريق توجيه آخر
                                    if ($newSupervisor->parent_id != $currentAdvisorId) {
                                        UserGroup::where('user_type', 'advisor')->where('group_id', $leaderGroupId)->update(['user_id'  => $newSupervisor->parent_id]);
                                    }
                                    $logInfo = ' قام ' . Auth::user()->name . " بنقل القائد " . $leader->name . ' إلى المراقب ' .  $newSupervisor->name;
                                    Log::channel('community_edits')->info($logInfo);

                                    $response['message'] = 'تم النقل';
                                } else {
                                    array_push($response['need_promotion'], $leaderToMove['leader_email']);
                                }
                            } else {
                                array_push($response['not_exists'], $leaderToMove['leader_email']);
                            }
                        }
                        return $this->jsonResponseWithoutMessage($response, 'data', 200);
                    } else {
                        $response['message'] = 'الفريق الرقابي الجديد غير موجود ';

                        return $this->jsonResponseWithoutMessage($response, 'data', 200);
                    }
                } else {
                    $response['message'] = 'يجب أن يكون المراقب الجديد مراقباً أولاً';

                    return $this->jsonResponseWithoutMessage($response, 'data', 200);
                }
            } else {
                $response['message'] = 'المراقب الجديد غير موجود';

                return $this->jsonResponseWithoutMessage($response, 'data', 200);
            }
        } else {
            throw new NotAuthorized;
        }
    }
}
