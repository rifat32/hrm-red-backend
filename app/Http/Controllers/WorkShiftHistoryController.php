<?php

namespace App\Http\Controllers;

use App\Exports\WorkShiftsExport;
use App\Http\Components\WorkShiftHistoryComponent;
use App\Http\Requests\GetIdRequest;
use App\Http\Requests\WorkShiftCreateRequest;
use App\Http\Requests\WorkShiftHistoryUpdateRequest;
use App\Http\Requests\WorkShiftUpdateRequest;
use App\Http\Utils\BasicUtil;
use App\Http\Utils\BusinessUtil;
use App\Http\Utils\ErrorUtil;
use App\Http\Utils\ModuleUtil;
use App\Http\Utils\UserActivityUtil;
use App\Models\Attendance;
use App\Models\BusinessTime;
use App\Models\Department;
use App\Models\EmployeeUserWorkShiftHistory;
use App\Models\WorkShiftHistory;
use App\Models\User;

use App\Models\WorkShift;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelExcel;
use PDF;
use Maatwebsite\Excel\Facades\Excel;

class WorkShiftHistoryController extends Controller
{
    use ErrorUtil, UserActivityUtil, BusinessUtil, BasicUtil, ModuleUtil;


    protected $workShiftHistoryComponent;


    public function __construct(WorkShiftHistoryComponent $workShiftHistoryComponent,)
    {
        $this->workShiftHistoryComponent = $workShiftHistoryComponent;
    }






    /**
     *
     *     @OA\Delete(
     *      path="/v1.0/work-shift-histories/{ids}/{user_id}",
     *      operationId="deleteWorkShiftHistoriesByIds",
     *      tags={"work_shift_histories"},
     *       security={
     *           {"bearerAuth": {}}
     *       },
     *              @OA\Parameter(
     *         name="ids",
     *         in="path",
     *         description="ids",
     *         required=true,
     *  example="1,2,3"
     *      ),
     *      summary="This method is to delete work shift by id",
     *      description="This method is to delete work shift by id",
     *

     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *       @OA\JsonContent(),
     *       ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthenticated",
     * @OA\JsonContent(),
     *      ),
     *        @OA\Response(
     *          response=422,
     *          description="Unprocesseble Content",
     *    @OA\JsonContent(),
     *      ),
     *      @OA\Response(
     *          response=403,
     *          description="Forbidden",
     *   @OA\JsonContent()
     * ),
     *  * @OA\Response(
     *      response=400,
     *      description="Bad Request",
     *   *@OA\JsonContent()
     *   ),
     * @OA\Response(
     *      response=404,
     *      description="not found",
     *   *@OA\JsonContent()
     *   )
     *      )
     *     )
     */

    public function deleteWorkShiftHistoriesByIds(Request $request, $ids,$user_id)
    {

        try {
            $this->storeActivity($request, "DUMMY activity", "DUMMY description");

            if (!$request->user()->hasPermissionTo('work_shift_delete')) {
                return response()->json([
                    "message" => "You can not perform this action"
                ], 401);
            }
            $all_manager_department_ids = $this->get_all_departments_of_manager();
            $business_id =  auth()->user()->business_id;
            $idsArray = explode(',', $ids);

            $existingIds = EmployeeUserWorkShiftHistory::

                whereIn('work_shift_id', $idsArray)
                ->where('user_id', $user_id)

                ->select('id')
                ->get()
                ->pluck('id')
                ->toArray();

            $nonExistingIds = array_diff($idsArray, $existingIds);

            if (!empty($nonExistingIds)) {

                return response()->json([
                    "message" => "Some or all of the specified data do not exist."
                ], 404);
            }

        $attendance =    Attendance::whereIn(
                "work_shift_history_id",$existingIds
            )
            ->first();
            if (!empty($attendance)) {
                return response()->json([
                    "message" => "Some attendance exists for this woek shift."
                ], 404);
            }

            EmployeeUserWorkShiftHistory::
                where('user_id', $user_id)
                    ->whereIn('work_shift_id', $idsArray)
                   ->delete();





            return response()->json(["message" => "data deleted sussfully", "deleted_ids" => $existingIds], 200);
        } catch (Exception $e) {

            return $this->sendError($e, 500, $request);
        }
    }

       /**
     *
     * @OA\Get(
     *      path="/v1.0/work-shift-histories/{id}",
     *      operationId="getWorkShiftHistoryById",
     *      tags={"work_shift_histories"},
     *       security={
     *           {"bearerAuth": {}}
     *       },
     *              @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="id",
     *         required=true,
     *  example="6"
     *      ),
     *      summary="This method is to get work shift by id",
     *      description="This method is to get work shift by id",
     *

     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *       @OA\JsonContent(),
     *       ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthenticated",
     * @OA\JsonContent(),
     *      ),
     *        @OA\Response(
     *          response=422,
     *          description="Unprocesseble Content",
     *    @OA\JsonContent(),
     *      ),
     *      @OA\Response(
     *          response=403,
     *          description="Forbidden",
     *   @OA\JsonContent()
     * ),
     *  * @OA\Response(
     *      response=400,
     *      description="Bad Request",
     *   *@OA\JsonContent()
     *   ),
     * @OA\Response(
     *      response=404,
     *      description="not found",
     *   *@OA\JsonContent()
     *   )
     *      )
     *     )
     */


     public function getWorkShiftHistoryById($id, Request $request)
     {
         try {
             $this->storeActivity($request, "DUMMY activity", "DUMMY description");
             if (!$request->user()->hasPermissionTo('work_shift_view')) {
                 return response()->json([
                     "message" => "You can not perform this action"
                 ], 401);
             }
             $business_id =  auth()->user()->business_id;

             $all_manager_department_ids = $this->get_all_departments_of_manager();

             $work_shift =  WorkShiftHistory::with("details", "departments", "users", "work_locations")
                 ->where([
                     "id" => $id
                 ])
                 ->where(function ($query) use ($all_manager_department_ids) {
                     $query
                         ->where([
                             "work_shift_histories.business_id" => auth()->user()->business_id
                         ])
                         ->whereHas("user.department_user.department", function ($query) use ($all_manager_department_ids) {
                             $query->whereIn("departments.id", $all_manager_department_ids);
                         });
                 })
                 ->first();

             if (!$work_shift) {
                 return response()->json([
                     "message" => "no work shift found"
                 ], 404);
             }


             return response()->json($work_shift, 200);
         } catch (Exception $e) {
             return $this->sendError($e, 500, $request);
         }
     }


          /**
     *
     * @OA\Get(
     *      path="/v1.0/current-work-shift-history/{employee_id}",
     *      operationId="getCurrentWorkShiftHistory",
     *      tags={"work_shift_histories"},
     *       security={
     *           {"bearerAuth": {}}
     *       },
     *              @OA\Parameter(
     *         name="employee_id",
     *         in="path",
     *         description="employee_id",
     *         required=true,
     *  example="6"
     *      ),
     *      summary="This method is to get work shift by id",
     *      description="This method is to get work shift by id",
     *

     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *       @OA\JsonContent(),
     *       ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthenticated",
     * @OA\JsonContent(),
     *      ),
     *        @OA\Response(
     *          response=422,
     *          description="Unprocesseble Content",
     *    @OA\JsonContent(),
     *      ),
     *      @OA\Response(
     *          response=403,
     *          description="Forbidden",
     *   @OA\JsonContent()
     * ),
     *  * @OA\Response(
     *      response=400,
     *      description="Bad Request",
     *   *@OA\JsonContent()
     *   ),
     * @OA\Response(
     *      response=404,
     *      description="not found",
     *   *@OA\JsonContent()
     *   )
     *      )
     *     )
     */


     public function getCurrentWorkShiftHistory($employee_id, Request $request)
     {
         try {
             $this->storeActivity($request, "DUMMY activity", "DUMMY description");
             if (!$request->user()->hasPermissionTo('work_shift_view')) {
                 return response()->json([
                     "message" => "You can not perform this action"
                 ], 401);
             }

             $all_manager_department_ids = $this->get_all_departments_of_manager();

                 $work_shift_history =  WorkShiftHistory::with("details")
                 ->where(function ($query) use ($all_manager_department_ids) {
                    $query
                        ->where([
                            "work_shift_histories.business_id" => auth()->user()->business_id
                        ])
                        ->whereHas("user.department_user.department", function ($query) use ($all_manager_department_ids) {
                            $query->whereIn("departments.id", $all_manager_department_ids);
                        });
                })
                ->where("user_id",$employee_id)
                 ->where(function ($query) use ( $employee_id) {
                         $query->where("from_date", "<=", today())
                             ->where(function ($query)  {
                                 $query->where("to_date", ">=", today())
                                     ->orWhereNull("to_date");
                             })

                             ;
                     })

                     ->orderByDesc("work_shift_histories.id")

                     ->first();




                     if (empty($work_shift_history)) {
                        throw new Exception("no work shift found for the user",404);
                     }





             return response()->json($work_shift_history, 200);



         } catch (Exception $e) {
             return $this->sendError($e, 500, $request);
         }
     }
}
