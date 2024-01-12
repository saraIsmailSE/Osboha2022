<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ResponseJson;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Exceptions\NotAuthorized;
use App\Exceptions\NotFound;
use App\Models\Group;
use App\Models\Mark;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Week;
use Illuminate\Support\Facades\DB;
use App\Traits\GroupTrait;

use function PHPUnit\Framework\returnSelf;

/**
 * Description: StatisticsController for Osboha general statistics.
 *
 * Methods: 
 * - byWeek
 */

class StatisticsController extends Controller
{

    use ResponseJson, GroupTrait;

    /**
     * Get Statistics By Week ID.
     * 
     * @return statistics;
     */

    public function byWeek($week_id = 0)
    {
        // not specified => get previous week
        if ($week_id == 0) {
            $week = Week::orderBy('created_at', 'desc')->skip(1)->take(2)->first();
        } else {
            $week = Week::latest()->pluck('id')->first();
        }
        $response['week'] = $week;

        //Total Pages, Theses, Screenshotes
        $response['total_statistics'] = Mark::without('user', 'week')->where('week_id', $response['week']->id)
            ->where('is_freezed', 0)
            ->select(
                DB::raw('avg(reading_mark + writing_mark + support) as total_avg'),
                DB::raw('sum(total_pages) as total_pages'),
                DB::raw('sum(total_thesis) as total_thesis'),
                DB::raw('sum(total_screenshot) as total_screenshot'),
            )->first();

        //Total 100
        $total_100 = Mark::without('user', 'week')->where('week_id', $response['week']->id)
            ->where('is_freezed', 0)
            ->select(
                DB::raw('sum(reading_mark + writing_mark + support) as total_100'),
            )->groupBy('user_id')->get();

        $response['total_100'] = $total_100->where('total_100', 100)->count();
        //Total 0
        $total_0 = Mark::without('user', 'week')->where('week_id', $response['week']->id)
            ->where('is_freezed', 0)
            ->select(
                DB::raw('sum(reading_mark + writing_mark + support) as total_0'),
            )->groupBy('user_id')->get();

        $response['total_0'] = $total_0->where('total_0', 0)->count();

        //Most Read
        $response['most_read'] = Mark::without('user', 'week')->where('week_id', $response['week']->id)
            ->where('is_freezed', 0)
            ->select('user_id', DB::raw('max(total_pages) as max_total_pages'))
            ->groupBy('user_id')
            ->orderBy('max_total_pages', 'desc')
            ->first();
        //Freezed
        $response['freezed'] = Mark::without('user', 'week')->where('week_id', $response['week']->id)
            ->where('is_freezed', 1)
            ->count();
        // Total Users
        $response['total_users'] = User::where('is_excluded', 0)->count();
        //Total Excluded
        $response['is_excluded'] = User::where('is_excluded', 1)
            ->whereBetween('updated_at', [$response['week']->created_at, $response['week']->created_at->addDays(7)])->count();
        //Total New
        $response['is_new'] = User::whereBetween('created_at', [$response['week']->created_at, $response['week']->created_at->addDays(7)])->get()->count();

        return $this->jsonResponseWithoutMessage($response, 'data', 200);
    }

    public function lastWeek()
    {
        $previous_week = Week::orderBy('created_at', 'desc')->skip(1)->take(2)->first();
        return $this->jsonResponseWithoutMessage($previous_week, 'data', 200);
    }

    /**
     * Get Statistics
     * 
     * @return statistics;
     */
    public function supervisingStatistics($superviser_id, $week_filter = "current")
    {
        $supervisingGroup = UserGroup::where('user_id', $superviser_id)->where('user_type', 'supervisor')
            ->whereHas('group.type', function ($q) {
                $q->where('type', '=', 'supervising');
            })->first();

        // الفريق الرقابي مع القادة بداخله

        $supervisorGroup = Group::without('type', 'Timeline')->with('userAmbassador')->find($supervisingGroup->group_id);
        if (!$supervisorGroup) {
            throw new NotFound;
        }


        //previous_week
        $previous_week = Week::orderBy('created_at', 'desc')->skip(1)->take(2)->first();
        //last_previous_week
         $last_previous_week = Week::orderBy('created_at', 'desc')->skip(2)->take(2)->first();
         $last_last_previous_week = Week::orderBy('created_at', 'desc')->skip(3)->take(2)->first();

        $response = [];

        $leadersIDs = $supervisorGroup->userAmbassador->pluck('id');

        //افرقة المتابعة الخاصة بالقادة
        $group_followups = UserGroup::with('user')->where('user_type', 'leader')
            ->whereIn('user_id', $leadersIDs)
            ->whereNull('termination_reason')
            ->get();


        foreach ($group_followups as $key => $group) {

            $leaderInfo['leader_name'] = $group->user->name;
            $leaderInfo['team'] = $group->group->name;

            //for each leader get follow up group with its ambassador 
            $followup = Group::without('type', 'Timeline')->with('leaderAndAmbassadors')->where('id', $group->group_id)->first();
            // Number of Ambassadors
            $leaderInfo['number_ambassadors'] = $followup->leaderAndAmbassadors->count();
            // last week Avg.
            $leaderInfo['week_avg'] = $this->groupAvg($group->group_id,  $previous_week->id, $followup->leaderAndAmbassadors->pluck('id'));


            // Number of freezed users in last week
            $leaderInfo['is_freezed'] = Mark::without('user')->where('week_id', $previous_week->id)
                ->whereIn('user_id', $followup->leaderAndAmbassadors->pluck('id'))
                ->where('is_freezed', 1)
                ->count();

            $memberCounts = UserGroup::where('group_id', $group->group_id)->where('updated_at','>=', $previous_week->created_at)
                ->where('user_type', 'ambassador')->select(
                    DB::raw("SUM(CASE WHEN termination_reason = 'excluded' THEN 1 ELSE 0 END) as excluded_members"),
                    DB::raw("SUM(CASE WHEN termination_reason = 'withdraw' THEN 1 ELSE 0 END) as withdraw_members")
                )
                ->first();

            // number of excluded users
            $leaderInfo['ambassadors_excluded_in_group'] = $memberCounts->excluded_members;

            // number of withdraw users
            $leaderInfo['ambassadors_withdraw_in_group'] = $memberCounts->withdraw_members;

            $leaderInfo['new_ambassadors'] = $this->newMembers($previous_week, $group->group_id);

            $followupLeaderAndAmbassadors = $followup->leaderAndAmbassadors->pluck('id');

            $markChanges = $this->markChanges($last_last_previous_week,$last_previous_week, $previous_week, $followupLeaderAndAmbassadors);


            // Count the number of users with mark changes
            $leaderInfo['number_zero_varible'] = $markChanges;

            $response['statistics_data'][$key] = $leaderInfo;
        }
        // leaders reading
        $response['leaders_reading'] = Mark::without('week')->where('week_id', $previous_week->id)
            ->whereIn('user_id', $leadersIDs)
            ->get();
            return  $response['statistics_data'];
        $response['supervisor_group'] = $supervisorGroup;


        return $this->jsonResponseWithoutMessage($response, 'data', 200);
    }

    public function advisorsStatistics($advisor_id, $week_filter = "current")
    {

        $advisingGroup = UserGroup::where('user_id', $advisor_id)->where('user_type', 'advisor')
            ->whereHas('group.type', function ($q) {
                $q->where('type', '=', 'advising');
            })->first();

        $advisorGroup = Group::without('type', 'Timeline')->with('userAmbassador')->find($advisingGroup->group_id);

        if (!$advisorGroup) {
            throw new NotFound;
        }


        //previous_week
        $previous_week = Week::orderBy('created_at', 'desc')->skip(1)->take(2)->first();
        //last_previous_week
        $last_previous_week = Week::orderBy('created_at', 'desc')->skip(2)->take(2)->first();

        $response = [];

        $supervisorsIDs = $advisorGroup->userAmbassador->pluck('id');

        //جرد أفرقة المتابعة الخاصة بالمراقبين
        $group_followups = UserGroup::with('user')->where('user_type', 'leader')
            ->whereIn('user_id', $supervisorsIDs)
            ->whereNull('termination_reason')
            ->get();

        foreach ($group_followups as $key => $group) {
            $response['supervisor_own_followup_team'][$key] = $this->followupTeamStatistics($group, $previous_week, $last_previous_week);
        }

        //جرد أفرقة المتابعة التي يشرف عليها المراقبين

        foreach ($supervisorsIDs as $key => $superviser_id) {
            $superviser = User::find($superviser_id);
            $response['supervisor_followup_teams'][$key] = $this->totalFollowupTeamStatistics($superviser, $previous_week, $last_previous_week);
        }

        // Supervisors reading
        $response['supervisors_reading'] = Mark::without('week')->where('week_id', $previous_week->id)
            ->whereIn('user_id', $supervisorsIDs)
            ->get();

        $response['advisor_group'] = $advisorGroup;


        return $this->jsonResponseWithoutMessage($response, 'data', 200);
    }


    public function consultantsStatistics($consultant_id, $week_filter = "current")
    {

        $consultantingGroup = UserGroup::where('user_id', $consultant_id)->where('user_type', 'consultant')
            ->whereHas('group.type', function ($q) {
                $q->where('type', '=', 'consultation');
            })->first();

        if (!$consultantingGroup) {
            throw new NotFound;
        }

        //previous_week
        $previous_week = Week::orderBy('created_at', 'desc')->skip(1)->take(2)->first();
        //last_previous_week
        $last_previous_week = Week::orderBy('created_at', 'desc')->skip(2)->take(2)->first();

        $response = [];

        $advisors = User::without('userProfile')->where('parent_id', $consultant_id)->get();

        //جرد أفرقة المتابعة التي يشرف عليها الموجهين
        $i = 0;
        foreach ($advisors as $advisor) {
            $advisingGroup = UserGroup::where('user_id', $advisor->id)->where('user_type', 'advisor')
                ->whereHas('group.type', function ($q) {
                    $q->where('type', '=', 'advising');
                })->first();


            $supervisors = User::without('userProfile')->where('parent_id', $advisor->id)->role('supervisor')->get();
            $week_avg = 0;
            $advisor_statistics = [];
            $advisor_statistics['number_of_leaders'] = 0;

            $advisor_statistics['is_freezed'] = 0;


            $advisor_statistics['ambassadors_excluded_in_group'] = 0;


            $advisor_statistics['ambassadors_withdraw_in_group'] = 0;

            $advisor_statistics['new_ambassadors'] = 0;
            $advisor_statistics['number_zero_varible'] = 0;

            foreach ($supervisors as $key => $superviser) {

                $supervisorStatistics['supervisor_followup_teams'][$key] = $this->totalFollowupTeamStatistics($superviser, $previous_week, $last_previous_week);

                if (!is_null($supervisorStatistics['supervisor_followup_teams'][$key]['team'])) {
                    $advisor_statistics['number_of_leaders'] += $supervisorStatistics['supervisor_followup_teams'][$key]['number_of_leaders'];

                    $week_avg += $supervisorStatistics['supervisor_followup_teams'][$key]['week_avg'];

                    $advisor_statistics['is_freezed'] += $supervisorStatistics['supervisor_followup_teams'][$key]['is_freezed'];


                    $advisor_statistics['ambassadors_excluded_in_group'] += $supervisorStatistics['supervisor_followup_teams'][$key]['ambassadors_excluded_in_group'];


                    $advisor_statistics['ambassadors_withdraw_in_group'] += $supervisorStatistics['supervisor_followup_teams'][$key]['ambassadors_withdraw_in_group'];

                    $advisor_statistics['new_ambassadors']  += $supervisorStatistics['supervisor_followup_teams'][$key]['new_ambassadors'];
                    $advisor_statistics['number_zero_varible']  += $supervisorStatistics['supervisor_followup_teams'][$key]['number_zero_varible'];
                }
            }


            $advisor_statistics['advisor_name'] = $advisor->name;
            $advisor_statistics['advisor_id'] = $advisor->id;
            $advisor_statistics['team'] = $advisingGroup->group->name;

            $advisor_statistics['number_of_supervisors'] = $supervisors->count();
            $advisor_statistics['week_avg'] = $week_avg / $advisor_statistics['number_of_supervisors'];

            $response['advisor_statistics'][$i] = $advisor_statistics;
            $i++;
        }

        // Supervisors reading
        $response['advisors_reading'] = Mark::without('week')->where('week_id', $previous_week->id)
            ->whereIn('user_id', $advisors->pluck('id'))
            ->get();

        $response['consultant_group'] = $consultantingGroup->group;


        return $this->jsonResponseWithoutMessage($response, 'data', 200);
    }

    public function administratorStatistics($administrator_id, $week_filter = "current")
    {

        $administrationGroup = UserGroup::where('user_id', $administrator_id)->where('user_type', 'admin')
            ->whereHas('group.type', function ($q) {
                $q->where('type', '=', 'Administration');
            })->first();

        if (!$administrationGroup) {
            throw new NotFound;
        }

        //previous_week
        $previous_week = Week::orderBy('created_at', 'desc')->skip(1)->take(2)->first();
        //last_previous_week
        $last_previous_week = Week::orderBy('created_at', 'desc')->skip(2)->take(2)->first();

        $response = [];

        $consultants = User::without('userProfile')->where('parent_id', $administrator_id)->get();

        //جرد أفرقة المتابعة التي يشرف عليها الموجهين
        $i = 0;

        foreach ($consultants as $consultant) {
            $consultationGroup = UserGroup::where('user_id', $consultant->id)->where('user_type', 'consultant')
                ->whereHas('group.type', function ($q) {
                    $q->where('type', '=', 'consultation');
                })->first();

            $week_avg = 0;
            $number_ambassadors=0;
            $consultant_statistics = [];

            $consultant_statistics['consultant_name'] = $consultant->name;
            $consultant_statistics['consultant_id'] = $consultant->id;
            if(!is_null($consultationGroup)){
                $consultant_statistics['team'] = $consultationGroup->group->name;
            }
            else{
                $consultant_statistics['team'] = "لا يوجد";
            }

            $consultant_statistics['number_of_advisors'] = User::where('parent_id', $consultant->id)->role('advisor')->count();
            
            $response['consultant_statistics'][$i] = $consultant_statistics;
            $i++;
        }

        // Supervisors reading
        $response['consultants_reading'] = Mark::without('week')->where('week_id', $previous_week->id)
            ->whereIn('user_id', $consultants->pluck('id'))
            ->get();

        $response['administrator_group'] = $administrationGroup->group;


        return $this->jsonResponseWithoutMessage($response, 'data', 200);
    }


    private function followupTeamStatistics($group, $previous_week, $last_previous_week)
    {
        $teamStatistics['leader_name'] = $group->user->name;
        $teamStatistics['team'] = $group->group->name;

        //for each leader get follow up group with its ambassador 
        $followup = Group::without('type', 'Timeline')->with('leaderAndAmbassadors')->where('id', $group->group_id)->first();
        // Number of Ambassadors
        $teamStatistics['number_ambassadors'] = $followup->leaderAndAmbassadors->count();
        // last week Avg.
        $teamStatistics['week_avg'] = $this->groupAvg($group->group_id,  $previous_week->id, $followup->leaderAndAmbassadors->pluck('id'));


        // Number of freezed users in last week
        $teamStatistics['is_freezed'] = Mark::without('user')->where('week_id', $previous_week->id)
            ->whereIn('user_id', $followup->leaderAndAmbassadors->pluck('id'))
            ->where('is_freezed', 1)
            ->count();

        $memberCounts = UserGroup::where('group_id', $group->group_id)->where('updated_at','>=', $previous_week->created_at)
            ->where('user_type', 'ambassador')->select(
                DB::raw("SUM(CASE WHEN termination_reason = 'excluded' THEN 1 ELSE 0 END) as excluded_members"),
                DB::raw("SUM(CASE WHEN termination_reason = 'withdraw' THEN 1 ELSE 0 END) as withdraw_members")
            )
            ->first();

        // number of excluded users
        $teamStatistics['ambassadors_excluded_in_group'] = $memberCounts->excluded_members;

        // number of withdraw users
        $teamStatistics['ambassadors_withdraw_in_group'] = $memberCounts->withdraw_members;

        $teamStatistics['new_ambassadors'] = $this->newMembers($previous_week, $group->id);

        $followupLeaderAndAmbassadors = $followup->leaderAndAmbassadors->pluck('id');

        $markChanges = $this->markChanges($last_last_previous_week,$last_previous_week, $previous_week, $followupLeaderAndAmbassadors);
        

        // Count the number of users with mark changes
        $teamStatistics['number_zero_varible'] = $markChanges;

        return $teamStatistics;
    }

    private function totalFollowupTeamStatistics($superviser, $previous_week, $last_previous_week)
    {

        $teamStatistics['superviser_name'] = $superviser->name;
        $teamStatistics['supervisor_id'] = $superviser->id;

        $supervisingGroup = UserGroup::where('user_id', $superviser->id)->where('user_type', 'supervisor')
            ->whereHas('group.type', function ($q) {
                $q->where('type', '=', 'supervising');
            })->first();

        if (!$supervisingGroup) {
            $teamStatistics['team'] = null;

            return $teamStatistics;
        }
        $supervisorGroup = Group::without('type', 'Timeline')->with('userAmbassador')->find($supervisingGroup->group_id);

        // Number of Leaders
        $teamStatistics['number_of_leaders'] = $supervisorGroup->userAmbassador->count();

        $leadersIDs = $supervisorGroup->userAmbassador->pluck('id');

        //افرقة المتابعة الخاصة بالقادة
        $group_followups = UserGroup::where('user_type', 'leader')
            ->whereIn('user_id', $leadersIDs)
            ->whereNull('termination_reason')
            ->get();

        $allLeadersAndAmbassadors = UserGroup::whereIn('user_type', ['ambassador', 'leader'])
            ->whereIn('group_id', $group_followups->pluck('group_id'))
            ->whereNull('termination_reason')
            ->get();

        $allLeadersAndAmbassadorsIDS = $allLeadersAndAmbassadors->pluck('user_id');

        $teamStatistics['team'] = $supervisorGroup->name;


        //for each supervisor get leaders follow up group with its ambassador 
        $followups = Group::without('type', 'Timeline')->with('leaderAndAmbassadors')->whereIn('id', $group_followups->pluck('group_id'))->get();

        // last week Avg.
        $teamStatistics['week_avg'] = $this->groupAvg(1,  $previous_week->id, $allLeadersAndAmbassadorsIDS);


        // Number of freezed users in last week
        $teamStatistics['is_freezed'] = Mark::without('user')->where('week_id', $previous_week->id)
            ->whereIn('user_id', $allLeadersAndAmbassadorsIDS)
            ->where('is_freezed', 1)
            ->count();

        $memberCounts = UserGroup::whereIn('group_id', $allLeadersAndAmbassadors->pluck('group_id'))->where('updated_at','>=', $previous_week->created_at)
            ->where('user_type', 'ambassador')->select(
                DB::raw("SUM(CASE WHEN termination_reason = 'excluded' THEN 1 ELSE 0 END) as excluded_members"),
                DB::raw("SUM(CASE WHEN termination_reason = 'withdraw' THEN 1 ELSE 0 END) as withdraw_members")
            )
            ->first();

        // number of excluded users
        $teamStatistics['ambassadors_excluded_in_group'] = $memberCounts->excluded_members;

        // number of withdraw users
        $teamStatistics['ambassadors_withdraw_in_group'] = $memberCounts->withdraw_members;

        $teamStatistics['new_ambassadors'] = UserGroup::whereIn('group_id', $allLeadersAndAmbassadors->pluck('group_id'))
            ->whereBetween('created_at', [$previous_week->created_at, $previous_week->created_at->addDays(7)])->get()->count();

        $markChanges = $this->markChanges($last_last_previous_week,$last_previous_week, $previous_week, $allLeadersAndAmbassadorsIDS);

        // Count the number of users with mark changes
        $teamStatistics['number_zero_varible'] = $markChanges;

        return $teamStatistics;
    }

    private function markChanges($last_last_previous_week,$last_previous_week, $previous_week, $followupLeaderAndAmbassadors)
    {


        $secondLastWeekMarks = Mark::without('user')->select('user_id', DB::raw('COALESCE(SUM(reading_mark + writing_mark), 0) as previous_week_mark'))
            ->where('week_id', $last_last_previous_week->id)
            ->whereIn('user_id', $followupLeaderAndAmbassadors)
            ->groupBy('user_id');

        $markChanges['last_last_previous_week'] = Mark::without('user')->select(
            'marks.user_id',
            DB::raw('COALESCE(SUM(reading_mark + writing_mark), 0) as last_week_mark'),
            DB::raw('previous_week_mark')
        )
            ->leftJoinSub($secondLastWeekMarks, 'previous_marks', function ($join) {
                $join->on('marks.user_id', '=', 'previous_marks.user_id');
            })
            ->where('marks.week_id', $last_previous_week->id)
            ->groupBy('marks.user_id')
            ->havingRaw('previous_week_mark = 0 AND last_week_mark > 0')
            ->count();


        $secondLastWeekMarks = Mark::without('user')->select('user_id', DB::raw('COALESCE(SUM(reading_mark + writing_mark), 0) as previous_week_mark'))
            ->where('week_id', $last_previous_week->id)
            ->whereIn('user_id', $followupLeaderAndAmbassadors)
            ->groupBy('user_id');

        $markChanges['last_previous_week'] = Mark::without('user')->select(
            'marks.user_id',
            DB::raw('COALESCE(SUM(reading_mark + writing_mark), 0) as last_week_mark'),
            DB::raw('previous_week_mark')
        )
            ->leftJoinSub($secondLastWeekMarks, 'previous_marks', function ($join) {
                $join->on('marks.user_id', '=', 'previous_marks.user_id');
            })
            ->where('marks.week_id', $previous_week->id)
            ->groupBy('marks.user_id')
            ->havingRaw('previous_week_mark = 0 AND last_week_mark > 0')
            ->count();    

        return $markChanges;
    }

    private function newMembers($previous_week, $group_id)
    {
        return UserGroup::without('user')->where('group_id', $group_id)
            ->whereBetween('created_at', [$previous_week->created_at, $previous_week->created_at->addDays(7)])->get()->count();
    }
}
