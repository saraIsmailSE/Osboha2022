<?php

namespace App\Http\Controllers\Api\Ramadan;

use App\Http\Controllers\Controller;
use App\Models\RamadanDay;
use App\Models\RamadanHadith;
use App\Traits\ResponseJson;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RamadanHadithController extends Controller
{
    use ResponseJson;

    /**
     * @author Asmaa
     * Get all hadiths based on the days of Ramadan
     * 
     * @return ResponseJson
     */
    public function index()
    {
        //get all days with their hadiths and the memorized hadith for authenticated user of each hadith
        $data = RamadanDay::with(['hadiths' => function ($query) {
            $query->with(['memorization' => function ($query) {
                $query->where('user_id', Auth::id());
            }]);
        }])->get();

        return $this->jsonResponseWithoutMessage($data, 'data', Response::HTTP_OK);
    }

    /**
     * @author Asmaa
     * Get day's hadiths
     * 
     * @param int $dayId
     * 
     * @return ResponseJson
     */
    public function getHadithByDay($dayId)
    {
        $day = RamadanDay::find($dayId);
        if (!$day) {
            return $this->jsonResponseWithoutMessage('اليوم غير موجود', 'data', Response::HTTP_NOT_FOUND);
        }

        $data = $day->hadiths()->with(['memorization' => function ($query) {
            $query->where('user_id', Auth::id());
        }])->get();

        return $this->jsonResponseWithoutMessage($data, 'data', Response::HTTP_OK);
    }
}
