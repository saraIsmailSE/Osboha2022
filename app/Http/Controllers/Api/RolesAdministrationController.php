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


    /**
     * assign role and assign head user to a user .
     * 
     * @return jsonResponseWithoutMessage;
     */

    public function assignRole(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user' => 'required|email',
            'head_user' => 'required|email',
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

                    //if last role less than the new role => assign ew role
                    if ($user_current_role->id > $role->id) {

                        //remove last role if not ambassador or leader and new role is supervisor                                    
                        if ($user_current_role->name !== 'ambassador' && !($user_current_role->name === 'leader' && $role->name === 'supervisor')) {
                            $user->removeRole($user_current_role->name);
                        }

                        //else remove other roles
                    } else {
                        //remove all roles except the ambassador                        
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
                    return $this->jsonResponseWithoutMessage($successMessage, 'data', 202);
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

                        DB::commit();
                        return $this->jsonResponseWithoutMessage("تم التبديل", 'data', 200);
                    } catch (\Exception $e) {
                        //Log::channel('auditMarks')->info($e);
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
     * @param Request contains supervisor1 email [current], supervisor2 email [new]
     * @return jsonResponseWithoutMessage;
     */

    public function supervisorsSwap(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supervisor1' => 'required|email',
            'supervisor2' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {
            //get supervisors info
            $supervisor1 = User::where('email', $request->supervisor1)->first();
            $supervisor2 = User::where('email', $request->supervisor2)->first();

            if ($supervisor1 && $supervisor2) {
                //check if supervisor has supervisor role 
                if ($supervisor1->hasRole('supervisor') && $supervisor2->hasRole('supervisor')) {

                    //supervising group and its leaders [leaders here are ambassadors] for  supervisor 1

                    $supervising1Group = Group::whereHas('type', function ($q) {
                        $q->where('type', 'supervising');
                    })
                        ->whereHas('users', function ($q) use ($supervisor1) {
                            $q->where('user_id', $supervisor1->id);
                        })
                        ->with('userAmbassador')
                        ->first();

                    $supervising2Group = Group::whereHas('type', function ($q) {
                        $q->where('type', 'supervising');
                    })
                        ->whereHas('users', function ($q) use ($supervisor2) {
                            $q->where('user_id', $supervisor2->id);
                        })
                        ->with('userAmbassador')
                        ->first();

                    if ($supervising1Group) {
                        foreach ($supervising1Group->userAmbassador as $leader) {
                            // for each leader in group 1
                            //update supervisor 
                            $leader->update(['parent_id'  => $supervisor2->id]);
                            //move leader to new supervising group
                            UserGroup::where('user_type', 'ambassador')->where('user_id', $leader->id)->update(['group_id'  => $supervising2Group->id]);
                            //update supervisor in following up leader group
                            $follow_up_group_id = UserGroup::where('user_type', 'leader')->where('user_id', $leader->id)->pluck('group_id')->first();
                            UserGroup::where('user_type', 'supervisor')->where('group_id', $follow_up_group_id)->update(['user_id'  => $supervisor2->id]);
                        }
                    }

                    if ($supervising2Group) {
                        foreach ($supervising2Group->userAmbassador as $leader) {
                            // for each leader in group 1
                            //update supervisor 
                            $leader->update(['parent_id'  => $supervisor1->id]);
                            //move leader to new supervising group
                            UserGroup::where('user_type', 'ambassador')->where('user_id', $leader->id)->update(['group_id'  => $supervising1Group->id]);
                            //update supervisor in following up leader group
                            $follow_up_group_id = UserGroup::where('user_type', 'leader')->where('user_id', $leader->id)->pluck('group_id')->first();
                            UserGroup::where('user_type', 'supervisor')->where('group_id', $follow_up_group_id)->update(['user_id'  => $supervisor1->id]);
                        }
                    }

                    return $this->jsonResponseWithoutMessage("تم التبديل", 'data', 200);
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
     * @param Request contains supervisor1 email [current], supervisor2 email [new]
     * @return jsonResponseWithoutMessage;
     */

    public function newSupervisor_currentToAmbassador(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supervisor1' => 'required|email',
            'supervisor2' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {

            $currentSupervisor = User::where('email', $request->supervisor1)->first();
            if ($currentSupervisor) {
                //get new supervisor info
                $newSupervisor = User::where('email', $request->supervisor2)->first();
                if ($newSupervisor) {
                    //check if new supervisor have leader role 
                    if ($newSupervisor->hasRole('leader')) {
                        //
                    } else {
                        return $this->jsonResponseWithoutMessage("يجب أن يكون المراقب قائداَ أولاً", 'data', 200);
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
     * @param Request contains supervisor1 email [current], supervisor2 email [new]
     * @return jsonResponseWithoutMessage;
     */

    public function newSupervisor_currentToLeader(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supervisor1' => 'required|email',
            'supervisor2' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {

            $currentSupervisor = User::where('email', $request->supervisor1)->first();
            if ($currentSupervisor) {
                //get new supervisor info
                $newSupervisor = User::where('email', $request->supervisor2)->first();
                if ($newSupervisor) {
                    //check if new supervisor has supervisor role 

                    if ($newSupervisor->hasRole('supervisor')) {
                        //check if new supervisor has leader role 
                        if ($newSupervisor->hasRole('leader')) {
                            //get new supervisor followup group [where he is a leader]
                            $newSupervisor_leadingGroup = UserGroup::where('user_id', $newSupervisor->id)
                                ->where('user_type', 'leader')
                                ->whereNull('user_groups.termination_reason')
                                ->first();
                            if ($newSupervisor_leadingGroup) {
                                //set him as supervisor for this group 
                            }
                        } else {
                            return $this->jsonResponseWithoutMessage("يجب أن يكون المراقب قائداَ أولاً", 'data', 200);
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



    public function moveLeader(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'leader' => 'required|email',
            'newSupervisor' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {
            $leader = User::where('email', $request->leader)->first();
            $newSupervisor = User::where('email', $request->newSupervisor)->first();
            if ($leader && $newSupervisor) {
                if (!$leader->hasRole('leader')) {
                    return $this->jsonResponseWithoutMessage("يجب أن يكون العضو له دور قائد", 'data', 200);
                }
                if (!$newSupervisor->hasRole('supervisor')) {
                    return $this->jsonResponseWithoutMessage("يجب أن يكون العضو له دور مراقب", 'data', 200);
                }

                //update parent id
                $leader->update(['parent_id'  => $newSupervisor->id]);
                //get the supervising group for new supervisor
                $supervisorGroupId = Group::whereHas('type', function ($q) {
                    $q->where('type', 'supervising');
                })
                    ->whereHas('users', function ($q) use ($newSupervisor) {
                        $q->where('user_id', $newSupervisor->id);
                    })
                    ->pluck('id')
                    ->first();
                //move leader to new supervising group
                UserGroup::where('user_type', 'ambassador')->where('user_id', $leader->id)->update(['group_id'  => $supervisorGroupId]);
                // find leader group id
                $leaderGroupId = UserGroup::where('user_type', 'leader')->where('user_id', $leader->id)->pluck('group_id')->first();
                //add new supervisor to leader group
                UserGroup::where('user_type', 'supervisor')->where('group_id', $leaderGroupId)->update(['user_id'  => $newSupervisor->id]);

                return $this->jsonResponseWithoutMessage("تم النقل", 'data', 200);
            }

            return $this->jsonResponseWithoutMessage("حساب القائد أو المراقب غير موجود ", 'data', 200);
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
                    UserGroup::where('user_type', 'ambassador')->where('user_id', $ambassador->id)->update(['group_id'  => $newLeaderGroupId]);

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
     *  set new Leader and let the current ambassador only
     * @param Request contains newLeader email, currentLeader email
     * @return jsonResponseWithoutMessage;
     */

    public function newLeader_currentToAmbassador(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'newLeader_email' => 'required|email',
            'currentLeader_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage($validator->errors(), 'data', 500);
        }

        if (Auth::user()->hasanyrole('admin|advisor|consultant')) {
            $currentLeader = User::where('email', $request->currentLeader_email)->first();

            if ($currentLeader) {
                //get new leader info
                $newLeader = User::where('email', $request->newLeader_email)->first();

                if ($newLeader) { 
                    //check if new leader has leader role 
                    if ($newLeader->hasRole('leader')) {
                        
                        //get leader group id
                        $leaderGroupId = UserGroup::where('user_id', $currentLeader->id)
                            ->where('user_type', 'leader')
                            ->pluck('group_id')->first();

                        if ($leaderGroupId) {
                            // نقل سفراء القائد الحالي إلى القائد الجديد 
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

                            UserGroup::where('user_type', 'ambassador')->where('user_id', $newLeader->id)->update(['group_id'  => $supervisingGroupId]);

                            // المسؤول عن القائد الجديد هو المراقب 
                            User::where('id' , $newLeader->id)->update(['parent_id'  => User::where('id' , $currentLeader->id)->pluck('parent_id')->first()]);

                            // اضافة القائد الجديد إلى مجموعة المتابعة كقائد
                             UserGroup::where('user_type', 'leader')->where('group_id' , $leaderGroupId)->update(['user_id'  => $newLeader->id]);

                            //سحب رتبة القيادة من القائد الحالي
                            $currentLeader->removeRole('leader');

                            //المسؤول عن القائد الحالي هو القائد الجديد
                            User::where('id' , $currentLeader->id)->update(['parent_id'  => $newLeader->id]);
                            // اضافة القائد الحالي إلى مجموعة المتابعة الخاصة به كـ سفير
                            UserGroup::where('user_type', 'ambassador')->where('user_id', $currentLeader->id)->update(['group_id'  => $leaderGroupId]);
                           
                             return $this->jsonResponseWithoutMessage(" تم تبديل القائد وجعل القائد القديم سفير في نفس المجموعة", 'data', 200);
   
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
}
