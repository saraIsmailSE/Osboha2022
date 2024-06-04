<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\NotAuthorized;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserInfoResource;
use App\Models\Week;
use App\Models\WorkHour;
use App\Traits\PathTrait;
use App\Traits\ResponseJson;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class WorkingHourController extends Controller
{
    use ResponseJson, PathTrait;
    public function addWorkingHours(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            // 'minutes' => 'required|numeric',
            // "date" => "required|date",
            'working_hours' => 'required|array',
            'working_hours.*.minutes' => 'required|numeric',
            'working_hours.*.date' => 'required|date',
            "working_hours.*.week_id" => "required|exists:weeks,id",
        ]);

        if ($validator->fails()) {
            return $this->jsonResponseWithoutMessage(
                $validator->errors()->first(),
                'data',
                Response::HTTP_BAD_REQUEST,
            );
        }
        $user = Auth::user();

        DB::beginTransaction();

        try {
            //loop through working hours
            foreach ($request->working_hours as $working_hour) {
                $date = Carbon::parse($working_hour['date']);
                $week_id = $working_hour['week_id'];

                //find working hours for date part of created_at
                $workingHours = WorkHour::where("user_id", $user->id)
                    ->where("week_id", $week_id)
                    ->whereDate('created_at', $date)
                    ->first();

                if (!$workingHours) {
                    //if minutes is 0, don't create a record
                    if ($working_hour['minutes'] == 0) {
                        continue;
                    }
                    $workingHours = WorkHour::create([
                        "user_id" => $user->id,
                        "minutes" => $working_hour['minutes'],
                        "week_id" => $week_id,
                        "created_at" => $date,
                    ]);
                } else {
                    $workingHours->minutes = $working_hour['minutes'];
                    $workingHours->save();
                }
            }

            DB::commit();
            return $this->jsonResponseWithoutMessage(
                'تم إضافة ساعات العمل بنجاح',
                'data',
                Response::HTTP_OK
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonResponseWithoutMessage(
                'حدث خطأ ما',
                'data',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function getWorkingHours(Request $request)
    {
        $user = Auth::user();

        if (!Auth::user()->hasAnyRole(['admin', 'consultant', 'advisor', 'supervisor'])) {
            throw new NotAuthorized;
        }

        $previousWeek = false;

        if (Carbon::now()->dayOfWeek <= Carbon::WEDNESDAY)
            $previousWeek = true;

        //get weeks to be displayed in frontend
        $response['weeks'] = Week::orderBy('created_at', 'desc')->take($previousWeek ? 2 : 1)->get();

        $week_id = $request->week ? $request->week : Week::latest()->first()->id;

        //get all working hours grouped by created_at date
        $workingHours = WorkHour::where("user_id", $user->id)
            ->where("week_id", $week_id)
            ->orderBy('created_at', 'asc')
            ->get(["id", "minutes", "created_at"]);

        $response['workingHours'] = $workingHours;
        $response['totalMinutes'] = $workingHours->sum('minutes');
        // //get days from previous week till current week
        return $this->jsonResponseWithoutMessage(
            $response,
            'data',
            Response::HTTP_OK
        );
    }

    public function getWorkingHours_old()
    {
        $user = Auth::user();

        if (!Auth::user()->hasAnyRole(['admin', 'consultant', 'advisor', 'supervisor'])) {
            throw new NotAuthorized;
        }

        $currentWeek = Week::latest()->first();
        $previousWeek = null;

        if (Carbon::now()->dayOfWeek <= Carbon::WEDNESDAY)
            $previousWeek = Week::latest()->skip(1)->first();

        //get all working hours grouped by created_at date
        $workingHours = WorkHour::with('week')->where("user_id", $user->id)
            ->whereIn("week_id", [$currentWeek->id, optional($previousWeek)->id ?? 0])
            ->orderBy('created_at', 'asc')
            ->get();

        $groupedWorkingHours = $workingHours->groupBy(function ($item) {
            return Carbon::parse($item->created_at)->toDateString();
        })->map(function ($group) {
            return $group->sum('minutes');
        });

        //get days from previous week till current week
        $days = $this->getDaysData($previousWeek, $currentWeek, $groupedWorkingHours);

        $workingHoursList = $workingHours->groupBy(function ($item) {
            return $item->week->title;
        })->map(function ($group) {
            return [
                'workingHours' => $group,
                'totalMinutes' => $group->sum('minutes')
            ];
        });

        return $this->jsonResponseWithoutMessage(
            [
                "workingHours" =>  $groupedWorkingHours,
                "days" => $days,
                "workingHoursList" => $workingHoursList
            ],
            'data',
            Response::HTTP_OK
        );
    }

    public function getWorkingHoursStatistics_old(Request $request)
    {
        if (!Auth::user()->hasAnyRole(['admin'])) {
            throw new NotAuthorized;
        }

        /* Requirements:-
        - number of hours the last week
        - number of hours based on the selected month
        - working hours for each user grouped by week and role
        - working hours for each user in each week day
        */

        //get last 2 weeks
        $response['weeks'] = Week::orderBy('created_at', 'desc')->take(2)->get();

        //selected date
        $selected_date = $request->date;

        //if no date is selected, get the current date
        if ($selected_date) {
            $selected_date = Carbon::parse($selected_date)->toDateString();
        } else {
            $selected_date = Carbon::now()->toDateString();
        }

        $selected_month = Carbon::parse($selected_date)->month;
        $selected_year = Carbon::parse($selected_date)->year;

        $response['selectedMonth'] = $selected_month;

        //get the last week where year between created_at and main_timer and month between created_at and main_timer
        $weeks = Week::whereYear('created_at', '<=', $selected_year)
            ->whereYear('main_timer', '>=', $selected_year)
            ->whereMonth('created_at', '<=', $selected_month)
            ->whereMonth('main_timer', '>=', $selected_month)
            ->orderBy('created_at', 'desc');


        $lastWeek = $weeks->first();
        if (!$lastWeek) {
            return $this->jsonResponseWithoutMessage(
                $response,
                'data',
                Response::HTTP_OK
            );
        }

        $response['minutesOfLastWeek'] = WorkHour::where('week_id', $lastWeek->id)->sum('minutes');

        $weeksOfTheSelectedMonth = $weeks->pluck('id')->toArray();

        $workingHours = WorkHour::whereIn('week_id', $weeksOfTheSelectedMonth)
            ->orderBy('week_id', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $response['minutesOfSelectedMonth'] = $workingHours->sum('minutes');

        $groupedData = $workingHours

            //group by week title
            ->groupBy(function ($week) {
                return $week->week->title;
            })
            ->map(function ($group) {
                //group by user role
                return $group->groupBy(function ($item) {
                    return config('constants.ARABIC_ROLES')[$item->user->roles->first()->name];
                })
                    //sort by role id
                    ->sortBy(function ($item) {
                        return $item->first()->user->roles->first()->id;
                    })
                    ->map(function ($roleGroup) {
                        //group by user id
                        return $roleGroup->groupBy(function ($item) {
                            return $item->user->id;
                        })->map(function ($userGroup) {
                            //group by day
                            $days = $userGroup->groupBy(function ($item) {
                                //get day of week of created_at (start from 1 for sunday to 7 for saturday)
                                return Carbon::parse($item->created_at)->dayOfWeek + 1;
                            })->map(function ($dayGroup) {
                                //sum minutes of each day
                                return $dayGroup->sum('minutes');
                            });

                            //return user info with days and total minutes
                            $user = $userGroup->first()->user;
                            return [
                                "user" => UserInfoResource::make($user),
                                "days" => $days,
                                "minutes" => $days->sum(),
                            ];
                        })
                            //sort by minutes
                            ->sortByDesc('minutes')
                            //return values only without keys
                            ->values()
                            ->toArray();
                    });
            });


        // return Excel::download(new WorkingHoursExport($groupedData), 'working_hours.xlsx');

        $response['workingHours'] = $groupedData;
        return $this->jsonResponseWithoutMessage($response, 'data', Response::HTTP_OK);
    }

    public function getWorkingHoursStatistics(Request $request)
    {
        if (!Auth::user()->hasAnyRole(['admin', 'consultant', 'advisor'])) {
            throw new NotAuthorized;
        }

        /* Requirements:-
      {
        current_week: [input is week id],
        previous_week: [input is week id],
        current_month: [input is year-month],
        previous_month: [input is year-month],
        last_previous_month: [input is year-month],
      }
        - number of hours the current_week, previous_week, current_month, previous_month, last_previous_month
        - working hours for each user in each day order by total from top to bottom
        */
        //get last 2 weeks
        $response['weeks'] = Week::orderBy('created_at', 'desc')->take(2)->get();

        //get last 3 months with their years and arabic names
        $response['months'] = [];
        for ($i = 0; $i < 3; $i++) {
            $month = Carbon::now()->subMonths($i)->month;
            $year = Carbon::now()->subMonths($i)->year;
            $response['months'][] = [
                "date" => $year . "-" . $month,
                'title' => config('constants.ARABIC_MONTHS')[$month] . " " . $year,
            ];
        }

        //inputs
        $type = $request->type ? $request->type : "week";
        $selectedDate = $request->date ? $request->date : Week::latest()->first()->id;

        $response['selectedType']  = $type;
        $response['selectedDate'] = $selectedDate;

        $weeks = null;
        $month = null;

        if ($type === "week") {
            $weeks = Week::where('id', $selectedDate)->orderBy('created_at', 'desc');
        } else {
            $date = Carbon::parse($selectedDate)->toDateString();
            $month = Carbon::parse($date)->month;
            $year = Carbon::parse($date)->year;

            $response['monthTitle'] = "شهر " . config('constants.ARABIC_MONTHS')[$month];
            $weeks =  Week::whereYear('created_at', '<=', $year)
                ->whereYear('main_timer', '>=', $year)
                ->whereMonth('created_at', '<=', $month)
                ->whereMonth('main_timer', '>=', $month)
                ->orderBy('created_at', 'desc');
        }

        $response['selectedMonth'] = $month;
        $response['minutesOfCurrentWeek'] = WorkHour::where('week_id', $response['weeks']->first()->id)->sum('minutes');

        if ($weeks->count() === 0) {
            return $this->jsonResponseWithoutMessage(
                $response,
                'data',
                Response::HTTP_OK
            );
        }

        $weeksIds = $weeks->pluck('id')->toArray();
        $workingHours = WorkHour::whereIn('week_id', $weeksIds)
            ->orderBy('week_id', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $response['minutesOfSelectedMonth'] = $type === "week" ? null : $workingHours->sum('minutes');

        //get working hours grouped by week then by user. each user has his/her working hours grouped by day
        //in each week, users are sorted by total minutes
        $groupedData = $workingHours
            //group by week title
            ->groupBy(function ($week) {
                return $week->week->title;
            })

            ->map(function ($group) {
                //group by user id
                return $group->groupBy(function ($item) {
                    return $item->user->id;
                })->map(function ($userGroup) {
                    //group by day
                    $days = $userGroup->groupBy(function ($item) {
                        //get day of week of created_at (start from 1 for sunday to 7 for saturday)
                        return Carbon::parse($item->created_at)->dayOfWeek + 1;
                    })->map(function ($dayGroup) {
                        //sum minutes of each day
                        return $dayGroup->sum('minutes');
                    });

                    //return user info with days and total minutes
                    $user = $userGroup->first()->user;
                    return [
                        "user" => UserInfoResource::make($user),
                        "days" => $days,
                        "minutes" => $days->sum(),
                    ];
                })
                    //sort by minutes
                    ->sortByDesc('minutes')
                    //return values only without keys
                    ->values()
                    ->toArray();
            })
            ->toArray();



        $response['workingHours'] = $groupedData;

        return $this->jsonResponseWithoutMessage(
            $response,
            'data',
            Response::HTTP_OK
        );
    }

    //private functions
    private function getDaysData($previousWeek, $currentWeek, $groupedWorkingHours)
    {
        $days = [];

        $this->processWeekDays($previousWeek, $groupedWorkingHours, $days);
        $this->processWeekDays($currentWeek, $groupedWorkingHours, $days);

        return $days;
    }

    private function processWeekDays($week, $groupedWorkingHours, &$days)
    {
        if (!$week) {
            return;
        }

        $createdAt = Carbon::parse($week->created_at);
        $mainTimer = Carbon::parse($week->main_timer);

        if ($createdAt->dayOfWeek === Carbon::SATURDAY) {
            $createdAt->addDay();
        }

        while ($createdAt->lte($mainTimer)) {
            if ($createdAt->toDateString() === $mainTimer->toDateString()) {
                break;
            }

            $days[] = [
                "week_id" => $week->id,
                "date" => $createdAt->toDateString(),
                "minutes" => $groupedWorkingHours[$createdAt->toDateString()] ?? 0,
                "week_title" => $week->title,
            ];
            $createdAt->addDay();
        }
    }
}
