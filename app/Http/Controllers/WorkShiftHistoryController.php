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
     * @OA\Put(
     *      path="/v1.0/work-shift-histories",
     *      operationId="updateWorkShiftHistory",
     *      tags={"work_shift_histories"},
     *       security={
     *           {"bearerAuth": {}}
     *       },
     *      summary="This method is to update work shift",
     *      description="This method is to update work_shift",
     *
     *  @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *      @OA\Property(property="id", type="number", format="number", example="Updated Christmas"),
     *     @OA\Property(property="name", type="string", format="string", example="Updated Christmas"),
     *     @OA\Property(property="type", type="string", format="string", example="regular"),
     *     @OA\Property(property="description", type="string", format="string", example="description"),
     *    *      *  *     @OA\Property(property="is_personal", type="boolean", format="boolean", example="0"),
     *   *     @OA\Property(property="break_type", type="string", format="string", example="paid"),
     *  *     @OA\Property(property="break_hours", type="boolean", format="boolean", example="0"),
     *
     *     @OA\Property(property="departments", type="string",  format="array", example={1,2,3,4}),
     *      *  *     @OA\Property(property="work_locations", type="string",  format="array", example={1,2,3}),

     *     @OA\Property(property="users", type="string", format="array", example={1,2,3}),
     * *     @OA\Property(property="details", type="string", format="array", example={
     *         {
     *             "day": "0",
     *             "start_at": "",
     *             "end_at": "",
     *             "is_weekend": 0
     *         },
     *         {
     *             "day": "1",
     *             "start_at": "",
     *             "end_at": "",
     *             "is_weekend": 0
     *         },
     *         {
     *             "day": "2",
     *             "start_at": "",
     *             "end_at": "",
     *             "is_weekend": 0
     *         },
     *         {
     *             "day": "3",
     *             "start_at": "",
     *             "end_at": "",
     *             "is_weekend": 0
     *         },
     *         {
     *             "day": "4",
     *             "start_at": "",
     *             "end_at": "",
     *             "is_weekend": 0
     *         },
     *         {
     *             "day": "5",
     *             "start_at": "",
     *             "end_at": "",
     *             "is_weekend": 0
     *         },
     *         {
     *             "day": "6",
     *             "start_at": "",
     *             "end_at": "",
     *             "is_weekend": 0
     *         }
     *     }),

     *     @OA\Property(property="start_date", type="string", format="date", example="2023-11-16"),
     *     @OA\Property(property="end_date", type="string", format="date", example=""),
     *

     *
     *         ),
     *      ),
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

    public function updateWorkShiftHistory(WorkShiftHistoryUpdateRequest $request)
    {

        try {
            $this->storeActivity($request, "DUMMY activity", "DUMMY description");
            return DB::transaction(function () use ($request) {

                if (!$request->user()->hasPermissionTo('work_shift_update')) {
                    return response()->json([
                        "message" => "You can not perform this action"
                    ], 401);
                }


                $request_data = $request->validated();
                $request_data["from_date"] = Carbon::parse($request_data["from_date"]);

                $work_shift_query_params = [
                    "id" => $request_data["id"],
                ];

                $work_shift_history = WorkShiftHistory::where($work_shift_query_params)->first();

                if (!$work_shift_history) {
                   throw new Exception("No work shift history found",400);
                }


                if ($request_data["type"] !== "flexible" && !empty(env("MATCH_BUSINESS_SCHEDULE")) && false) {
                    $check_work_shift_details =  $this->checkWorkShiftDetails($request_data['details']);
                    if (!$check_work_shift_details["ok"]) {
                        throw new Exception(json_encode($check_work_shift_details["error"]), $check_work_shift_details["status"]);
                    }
                } else {
                    $this->isModuleEnabled("flexible_shifts");
                }

                $work_shift_history_after =  WorkShiftHistory::
                     whereNotIn("id",[$request_data["id"]])
                    ->where(function ($query) use ($request_data) {
                    $query->whereDate("from_date", ">", $request_data["from_date"])
                    ->where("users_id", $request_data["user_id"]);
                })
                ->orderByDesc("work_shift_histories.id")
                ->first();

                if(!empty($work_shift_history_after)) {
                    $after_date = Carbon::parse($work_shift_history_after->from_date);
                    $request_data["to_date"] = $after_date->copy()->subDay();
                }

                $work_shift_history_before =  WorkShiftHistory::
                    whereNotIn("id",[$request_data["id"]])
                    ->where(function ($query) use ($request_data) {
                    $query->whereDate("from_date", "<=", $request_data["from_date"])
                        ->where(function ($query) use ($request_data) {
                            $query->whereDate("to_date", ">=", $request_data["from_date"])
                                ->orWhereNull("to_date");
                        })
                        ->where("users_id", $request_data["user_id"])
                       ;
                })

                ->orderByDesc("work_shift_histories.id")
                ->first();

                if(!empty($work_shift_history_before)) {
                    $attendance = Attendance::whereDate("in_date",">=", $request_data["from_date"])
                    ->where("work_shift_history_id",$work_shift_history_before)->first();
                    if(!empty($attendance)) {
                      throw new Exception("work shift can not overlap.",409);
                    } else {
                       $work_shift_history_before->to_date = Carbon::parse($request_data["from_date"])->copy()->subDay();
                       $work_shift_history_before->save();
                    }

                }

                $request_data["work_shift_id"] = NULL;

                if(empty($request_data["to_date"])) {
                    $request_data["to_date"] = NULL;
                } else {
                    $request_data["to_date"] = Carbon::parse($request_data["to_date"]);
                }





                    $work_shift_history->fill(
                        collect($request_data)->only([
                            'name',
                            'type',
                            'description',
                            'is_personal',
                            'break_type',
                            'break_hours',
                            'from_date',
                            'to_date',
                            'work_shift_id'
                            // "is_active",
                            // "business_id",
                            // "created_by"
                        ])->toArray()
                    )->save();



                // $work_shift_history->work_locations()->sync($request_data['work_locations']);

                $work_shift_history->details()->delete();
                $work_shift_history->details()->createMany($request_data['details']);





                return response($work_shift_history, 201);
            });
        } catch (Exception $e) {
            error_log($e->getMessage());
            return $this->sendError($e, 500, $request);
        }
    }



    /**
     *
     *     @OA\Delete(
     *      path="/v1.0/work-shift-histories/{ids}",
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

    public function deleteWorkShiftHistoriesByIds(Request $request, $ids)
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
            $existingIds = WorkShiftHistory::where([
                "business_id" => $business_id
            ])
            ->whereHas("user.department_user.department", function ($query) use ($all_manager_department_ids) {
                $query->whereIn("departments.id", $all_manager_department_ids);
            })
                ->whereIn('id', $idsArray)
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


            WorkShiftHistory::destroy($existingIds);



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
