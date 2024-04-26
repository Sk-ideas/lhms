<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Attendance;
use App\Models\ClassSection;
use App\Models\Exam;
use App\Models\ExamClass;
use App\Models\ExamMarks;
use App\Models\ExamTimetable;
use App\Models\File;
use App\Models\Holiday;
use App\Models\Lesson;
use App\Models\LessonTopic;
use App\Models\Students;
use App\Models\Subject;
use App\Models\SubjectTeacher;
use App\Models\Timetable;
use App\Rules\uniqueLessonInClass;
use App\Rules\uniqueTopicInLesson;
use App\Services\ResponseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use JetBrains\PhpStorm\NoReturn;
use Throwable;

//use App\Models\Parents;

class TeacherApiController extends Controller {
    #[NoReturn] public function login(Request $request) {
        if (Auth::attempt([
            'email'    => $request->email,
            'password' => $request->password
        ])) {
            $auth = Auth::user();
            if ($request->fcm_id) {
                $auth->fcm_id = $request->fcm_id;
                $auth->save();
            }
            // Check school status is activated or not
            if ($auth->school->status == 0 || $auth->status == 0) {
                $auth->fcm_id = '';
                $auth->save();
                ResponseService::errorResponse(trans('your_account_has_been_deactivated_please_contact_admin'), null, config('constants.RESPONSE_CODE.INVALID_LOGIN'));
            }

            $token = $auth->createToken($auth->first_name)->plainTextToken;
            $user = $auth->load(['teacher']);
            ResponseService::successResponse('User logged-in!', $user, ['token' => $token], config('constants.RESPONSE_CODE.LOGIN_SUCCESS'));
        }

        ResponseService::errorResponse('Invalid Login Credentials', null, config('constants.RESPONSE_CODE.INVALID_LOGIN'));
    }

    public function classes(Request $request) {
        try {
            $user = $request->user()->teacher;
            //Find the class in which teacher is assigns as Class Teacher
            if ($user->class_section) {
                $class_teacher = $user->class_section->load('class', 'section', 'medium');
            }

            //Find the Classes in which teacher is taking subjects
            $class_section_ids = $user->classes()->pluck('class_section_id');

            $class_section = ClassSection::whereIN('id', $class_section_ids)->with('class', 'section', 'medium');
            if (!empty($class_teacher)) {
                $class_section = $class_section->where(function ($q) use ($class_teacher) {
                    $q->where('class_id', '!=', $class_teacher->class_id)->whereOr('section_id', '!=', $class_teacher->section_id);
                });
            }
            $class_section = $class_section->get();
            ResponseService::successResponse('Teacher Classes Fetched Successfully.', null, [
                'class_teacher' => $class_teacher ?? (object)null,
                'other'         => $class_section
            ]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function subjects(Request $request) {
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'nullable|numeric',
            'subject_id'       => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $user = $request->user();
            $teacher = $user->teacher;
            $subjects = $teacher->subjects();
            if ($request->class_section_id) {
                $subjects = $subjects->where('class_section_id', $request->class_section_id);
            }

            if ($request->subject_id) {
                $subjects = $subjects->where('subject_id', $request->subject_id);
            }
            $subjects = $subjects->with('subject', 'class_section')->get();
            ResponseService::successResponse('Teacher Subject Fetched Successfully.', $subjects);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }


    public function getAssignment(Request $request) {
        ResponseService::noPermissionThenSendJson('assignment-list');
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'nullable|numeric',
            'subject_id'       => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sql = Assignment::assignmentteachers()->with('class_section', 'file', 'subject');
            if ($request->class_section_id) {
                $sql = $sql->where('class_section_id', $request->class_section_id);
            }

            if ($request->subject_id) {
                $sql = $sql->where('subject_id', $request->subject_id);
            }
            $data = $sql->orderBy('id', 'DESC')->paginate();
            ResponseService::successResponse('Assignment Fetched Successfully.', $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function createAssignment(Request $request) {
        ResponseService::noPermissionThenSendJson('assignment-create');
        $validator = Validator::make($request->all(), [
            "class_section_id"            => 'required|numeric',
            "subject_id"                  => 'required|numeric',
            "name"                        => 'required',
            "instructions"                => 'nullable',
            "due_date"                    => 'required|date',
            "points"                      => 'nullable',
            "resubmission"                => 'nullable|boolean',
            "extra_days_for_resubmission" => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            $session_year_id = getSystemSettings('session_year');

            $assignment = new Assignment();
            $assignment->class_section_id = $request->class_section_id;
            $assignment->subject_id = $request->subject_id;
            $assignment->name = $request->name;
            $assignment->instructions = $request->instructions;
            $assignment->due_date = Carbon::parse($request->due_date)->format('Y-m-d H:i:s');
            $assignment->points = $request->points;
            if ($request->resubmission) {
                $assignment->resubmission = 1;
                $assignment->extra_days_for_resubmission = $request->extra_days_for_resubmission;
            } else {
                $assignment->resubmission = 0;
                $assignment->extra_days_for_resubmission = null;
            }
            $assignment->session_year_id = $session_year_id;

            $subject_name = Subject::select('name')->where('id', $request->subject_id)->pluck('name')->first();
            $title = 'New assignment added in ' . $subject_name;
            $body = $request->name;
            $type = "assignment";
            $user = Students::select('user_id')->where('class_section_id', $request->class_section_id)->get()->pluck('user_id');
            $assignment->save();
            send_notification($user, $title, $body, $type);

            if ($request->hasFile('file')) {
                foreach ($request->file as $file_upload) {
                    $file = new File();
                    $file->file_name = $file_upload->getClientOriginalName();
                    $file->type = 1;
                    $file->file_url = $file_upload->store('assignment', 'public');
                    $file->modal()->associate($assignment);
                    $file->save();
                }
            }

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function updateAssignment(Request $request) {
        ResponseService::noPermissionThenSendJson('assignment-edit');
        $validator = Validator::make($request->all(), [
            "assignment_id"               => 'required|numeric',
            "class_section_id"            => 'required|numeric',
            "subject_id"                  => 'required|numeric',
            "name"                        => 'required',
            "instructions"                => 'nullable',
            "due_date"                    => 'required|date',
            "points"                      => 'nullable',
            "resubmission"                => 'nullable|boolean',
            "extra_days_for_resubmission" => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $session_year_id = getSystemSettings('session_year');

            $assignment = Assignment::find($request->assignment_id);
            $assignment->class_section_id = $request->class_section_id;
            $assignment->subject_id = $request->subject_id;
            $assignment->name = $request->name;
            $assignment->instructions = $request->instructions;
            $assignment->due_date = Carbon::parse($request->due_date)->format('Y-m-d H:i:s');
            $assignment->points = $request->points;
            if ($request->resubmission) {
                $assignment->resubmission = 1;
                $assignment->extra_days_for_resubmission = $request->extra_days_for_resubmission;
            } else {
                $assignment->resubmission = 0;
                $assignment->extra_days_for_resubmission = null;
            }

            $assignment->session_year_id = $session_year_id;
            $subject_name = Subject::select('name')->where('id', $request->subject_id)->pluck('name')->first();
            $title = 'Update assignment in ' . $subject_name;
            $body = $request->name;
            $type = "assignment";
            $user = Students::select('user_id')->where('class_section_id', $request->class_section_id)->get()->pluck('user_id');
            $assignment->save();
            send_notification($user, $title, $body, $type);

            if ($request->hasFile('file')) {
                foreach ($request->file as $file_upload) {
                    $file = new File();
                    $file->file_name = $file_upload->getClientOriginalName();
                    $file->type = 1;
                    $file->file_url = $file_upload->store('assignment', 'public');
                    $file->modal()->associate($assignment);
                    $file->save();
                }
            }

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function deleteAssignment(Request $request) {
        ResponseService::noPermissionThenSendJson('assignment-delete');
        try {
            $assignment = Assignment::find($request->assignment_id);
            $assignment->delete();
            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getAssignmentSubmission(Request $request) {
        ResponseService::noPermissionThenSendJson('assignment-submission');
        $validator = Validator::make($request->all(), ['assignment_id' => 'required|nullable|numeric']);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $data = AssignmentSubmission::with('assignment.subject:id,name', 'student:id,user_id', 'student.user:first_name,last_name,id,image', 'file')
                ->where('assignment_id', $request->assignment_id)->get();
            ResponseService::successResponse('Assignment Fetched Successfully', $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function updateAssignmentSubmission(Request $request) {
        ResponseService::noPermissionThenSendJson('assignment-submission');
        $validator = Validator::make($request->all(), [
            'assignment_submission_id' => 'required|numeric',
            'status'                   => 'required|numeric|in:1,2',
            'points'                   => 'nullable|numeric',
            'feedback'                 => 'nullable'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $assignment_submission = AssignmentSubmission::findOrFail($request->assignment_submission_id);
            $assignment_submission->feedback = $request->feedback;
            if ($request->status == 1) {
                $assignment_submission->points = $request->points;
            } else {
                $assignment_submission->points = null;
            }

            $assignment_submission->status = $request->status;
            $assignment_submission->save();

            $assignment_data = Assignment::where('id', $assignment_submission->assignment_id)->with('subject')->first();
            $user = Students::select('user_id')->where('id', $assignment_submission->student_id)->get()->pluck('user_id');
            $title = '';
            $body = '';
            if ($request->status == 2) {
                $title = "Assignment rejected";
                $body = $assignment_data->name . " rejected in " . $assignment_data->subject->name . " subject";
            }
            if ($request->status == 1) {
                $title = "Assignment accepted";
                $body = $assignment_data->name . " accepted in " . $assignment_data->subject->name . " subject";
            }
            $type = "assignment";
            send_notification($user, $title, $body, $type);
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getLesson(Request $request) {
        ResponseService::noPermissionThenSendJson('lesson-list');
        $validator = Validator::make($request->all(), [
            'lesson_id'        => 'nullable|numeric',
            'class_section_id' => 'nullable|numeric',
            'subject_id'       => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sql = Lesson::with('file')->withCount('topic');

            if ($request->lesson_id) {
                $sql = $sql->where('id', $request->lesson_id);
            }

            if ($request->class_section_id) {
                $sql = $sql->where('class_section_id', $request->class_section_id);
            }

            if ($request->subject_id) {
                $sql = $sql->where('subject_id', $request->subject_id);
            }
            $data = $sql->orderBy('id', 'DESC')->get();
            ResponseService::successResponse('Lesson Fetched Successfully', $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function createLesson(Request $request) {
        ResponseService::noPermissionThenSendJson('lesson-create');
        $validator = Validator::make($request->all(), [
            'name'             => 'required',
            'description'      => 'required',
            'class_section_id' => 'required|numeric',
            'subject_id'       => 'required|numeric',
            'file'             => 'nullable|array',
            'file.*.type'      => 'nullable|in:1,2,3,4',
            'file.*.name'      => 'required_with:file.*.type',
            'file.*.thumbnail' => 'required_if:file.*.type,2,3,4',
            'file.*.file'      => 'required_if:file.*.type,1,3',
            'file.*.link'      => 'required_if:file.*.type,2,4',
            //            'file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
            //            'file.*.name' => 'required_with:file.*.type',
            //            'file.*.thumbnail' => 'required_if:file.*.type,youtube_link,video_upload,other_link',
            //            'file.*.file' => 'required_if:file.*.type,file_upload,video_upload',
            //            'file.*.link' => 'required_if:file.*.type,youtube_link,other_link',
            //Regex for YouTube Link
            // 'file.*.link'=>['required_if:file.*.type,youtube_link','regex:/^(?:https?:\/\/)?(?:www\.)?(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))((?:\w|-){11})(?:&list=(\S+))?$/'],
            //Regex for Other Link
            // 'file.*.link'=>'required_if:file.*.type,other_link|url'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        $validator2 = Validator::make($request->all(), [
            'name' => [
                'required',
                new uniqueLessonInClass($request->class_section_id, $request->subject_id)
            ]
        ]);
        if ($validator2->fails()) {
            ResponseService::errorResponse($validator2->errors()->first(), null, config('constants.RESPONSE_CODE.NOT_UNIQUE_IN_CLASS'));
        }
        try {
            $lesson = new Lesson();
            $lesson->name = $request->name;
            $lesson->description = $request->description;
            $lesson->class_section_id = $request->class_section_id;
            $lesson->subject_id = $request->subject_id;
            $lesson->save();

            if ($request->file) {
                foreach ($request->file as $file) {
                    if ($file['type']) {
                        $lesson_file = new File();
                        $lesson_file->file_name = $file['name'];
                        $lesson_file->modal()->associate($lesson);

                        if ($file['type'] == "1") {
                            $lesson_file->type = 1;
                            $lesson_file->file_url = $file['file']->store('lessons', 'public');
                        } elseif ($file['type'] == "2") {
                            $lesson_file->type = 2;
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            $lesson_file->file_url = $file['link'];
                        } elseif ($file['type'] == "3") {
                            $lesson_file->type = 3;
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            $lesson_file->file_url = $file['file']->store('lessons', 'public');
                        } elseif ($file['type'] == "4") {
                            $lesson_file->type = 4;
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            $lesson_file->file_url = $file['link'];
                        }
                        $lesson_file->save();
                    }
                }
            }

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function updateLesson(Request $request) {
        ResponseService::noPermissionThenSendJson('lesson-edit');
        $validator = Validator::make($request->all(), [
            'lesson_id'        => 'required|numeric',
            'name'             => 'required',
            'description'      => 'required',
            'class_section_id' => 'required|numeric',
            'subject_id'       => 'required|numeric',
            'edit_file'        => 'nullable|array',
            'edit_file.*.id'   => 'required|numeric',
            'edit_file.*.type' => 'nullable|in:1,2,3,4',
            'edit_file.*.name' => 'required_with:edit_file.*.type',
            'edit_file.*.link' => 'required_if:edit_file.*.type,2,4',
            'file'             => 'nullable|array',
            'file.*.type'      => 'nullable|in:1,2,3,4',
            'file.*.name'      => 'required_with:file.*.type',
            'file.*.thumbnail' => 'required_if:file.*.type,2,3,4',
            'file.*.file'      => 'required_if:file.*.type,1,3',
            'file.*.link'      => 'required_if:file.*.type,2,4',

            //            'edit_file' => 'nullable|array',
            //            'edit_file.*.id' => 'required|numeric',
            //            'edit_file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
            //            'edit_file.*.name' => 'required_with:edit_file.*.type',
            //            'edit_file.*.link' => 'required_if:edit_file.*.type,youtube_link,other_link',
            //
            //            'file' => 'nullable|array',
            //            'file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
            //            'file.*.name' => 'required_with:file.*.type',
            //            'file.*.thumbnail' => 'required_if:file.*.type,youtube_link,video_upload,other_link',
            //            'file.*.file' => 'required_if:file.*.type,file_upload,video_upload',
            //            'file.*.link' => 'required_if:file.*.type,youtube_link,other_link',

            //Regex for YouTube Link
            // 'file.*.link'=>['required_if:file.*.type,youtube_link','regex:/^(?:https?:\/\/)?(?:www\.)?(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))((?:\w|-){11})(?:&list=(\S+))?$/'],
            //Regex for Other Link
            // 'file.*.link'=>'required_if:file.*.type,other_link|url'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        $validator2 = Validator::make($request->all(), [
            'name' => [
                'required',
                new uniqueLessonInClass($request->class_section_id, $request->lesson_id)
            ]
        ]);
        if ($validator2->fails()) {
            ResponseService::errorResponse($validator2->errors()->first(), null, config('constants.RESPONSE_CODE.NOT_UNIQUE_IN_CLASS'));
        }
        try {
            $lesson = Lesson::find($request->lesson_id);
            $lesson->name = $request->name;
            $lesson->description = $request->description;
            $lesson->class_section_id = $request->class_section_id;
            $lesson->subject_id = $request->subject_id;
            $lesson->save();

            // Update the Old Files
            if ($request->edit_file) {
                foreach ($request->edit_file as $file) {
                    if ($file['type']) {
                        $lesson_file = File::find($file['id']);
                        if ($lesson_file) {
                            $lesson_file->file_name = $file['name'];

                            if ($file['type'] == "1") {
                                $lesson_file->type = 1;
                                if (!empty($file['file'])) {
                                    if (Storage::disk('public')->exists($lesson_file->getRawOriginal('file_url'))) {
                                        Storage::disk('public')->delete($lesson_file->getRawOriginal('file_url'));
                                    }
                                    $lesson_file->file_url = $file['file']->store('lessons', 'public');
                                }
                            } elseif ($file['type'] == "2") {
                                $lesson_file->type = 2;
                                if (!empty($file['thumbnail'])) {
                                    if (Storage::disk('public')->exists($lesson_file->getRawOriginal('file_url'))) {
                                        Storage::disk('public')->delete($lesson_file->getRawOriginal('file_url'));
                                    }
                                    $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                                }

                                $lesson_file->file_url = $file['link'];
                            } elseif ($file['type'] == "3") {
                                $lesson_file->type = 3;
                                if (!empty($file['file'])) {
                                    if (Storage::disk('public')->exists($lesson_file->getRawOriginal('file_url'))) {
                                        Storage::disk('public')->delete($lesson_file->getRawOriginal('file_url'));
                                    }
                                    $lesson_file->file_url = $file['file']->store('lessons', 'public');
                                }

                                if (!empty($file['thumbnail'])) {
                                    if (Storage::disk('public')->exists($lesson_file->getRawOriginal('file_url'))) {
                                        Storage::disk('public')->delete($lesson_file->getRawOriginal('file_url'));
                                    }
                                    $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                                }
                            } elseif ($file['type'] == "4") {
                                $lesson_file->type = 4;
                                if (!empty($file['thumbnail'])) {
                                    if (Storage::disk('public')->exists($lesson_file->getRawOriginal('file_url'))) {
                                        Storage::disk('public')->delete($lesson_file->getRawOriginal('file_url'));
                                    }
                                    $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                                }
                                $lesson_file->file_url = $file['link'];
                            }

                            $lesson_file->save();
                        }
                    }
                }
            }

            //Add the new Files
            if ($request->file) {
                foreach ($request->file as $file) {
                    if ($file['type']) {
                        $lesson_file = new File();
                        $lesson_file->file_name = $file['name'];
                        $lesson_file->modal()->associate($lesson);

                        if ($file['type'] == "1") {
                            $lesson_file->type = 1;
                            $lesson_file->file_url = $file['file']->store('lessons', 'public');
                        } elseif ($file['type'] == "2") {
                            $lesson_file->type = 2;
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            $lesson_file->file_url = $file['link'];
                        } elseif ($file['type'] == "3") {
                            $lesson_file->type = 3;
                            $lesson_file->file_url = $file['file']->store('lessons', 'public');
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                        } elseif ($file['type'] == "4") {
                            $lesson_file->type = 4;
                            $lesson_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            $lesson_file->file_url = $file['link'];
                        }
                        $lesson_file->save();
                    }
                }
            }

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function deleteLesson(Request $request) {
        ResponseService::noPermissionThenSendJson('lesson-delete');
        $validator = Validator::make($request->all(), ['lesson_id' => 'required|numeric',]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $lesson = Lesson::where('id', $request->lesson_id)->firstOrFail();
            $lesson->delete();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable) {
            ResponseService::errorResponse();
        }
    }

    public function getTopic(Request $request) {
        ResponseService::noPermissionThenSendJson('topic-list');
        $validator = Validator::make($request->all(), ['lesson_id' => 'required|numeric',]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sql = LessonTopic::lessontopicteachers()->with('lesson.class_section', 'lesson.subject', 'file');
            $data = $sql->where('lesson_id', $request->lesson_id)->orderBy('id', 'DESC')->get();
            ResponseService::successResponse('Topic Fetched Successfully', $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function createTopic(Request $request) {
        ResponseService::noPermissionThenSendJson('topic-create');
        $validator = Validator::make($request->all(), [
            'name'             => 'required',
            'description'      => 'required',
            'class_section_id' => 'required|numeric',
            'subject_id'       => 'required|numeric',
            'lesson_id'        => 'required|numeric',
            'file'             => 'nullable|array',
            'file.*.type'      => 'nullable|in:1,2,3,4',
            'file.*.name'      => 'required_with:file.*.type',
            'file.*.thumbnail' => 'required_if:file.*.type,2,3,4',
            'file.*.file'      => 'required_if:file.*.type,1,3',
            'file.*.link'      => 'required_if:file.*.type,2,4',
            //            'file' => 'nullable|array',
            //            'file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
            //            'file.*.name' => 'required_with:file.*.type',
            //            'file.*.thumbnail' => 'required_if:file.*.type,youtube_link,video_upload,other_link',
            //            'file.*.file' => 'required_if:file.*.type,file_upload,video_upload',
            //            'file.*.link' => 'required_if:file.*.type,youtube_link,other_link',
            //Regex for YouTube Link
            // 'file.*.link'=>['required_if:file.*.type,youtube_link','regex:/^(?:https?:\/\/)?(?:www\.)?(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))((?:\w|-){11})(?:&list=(\S+))?$/'],
            //Regex for Other Link
            // 'file.*.link'=>'required_if:file.*.type,other_link|url'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        $validator2 = Validator::make($request->all(), [
            'name' => [
                'required',
                new uniqueTopicInLesson($request->lesson_id)
            ]
        ]);
        if ($validator2->fails()) {
            ResponseService::errorResponse($validator2->errors()->first(), null, config('constants.RESPONSE_CODE.NOT_UNIQUE_IN_CLASS'));
        }

        try {
            $topic = new LessonTopic();
            $topic->name = $request->name;
            $topic->description = $request->description;
            $topic->lesson_id = $request->lesson_id;
            $topic->save();

            if ($request->file) {
                foreach ($request->file as $data) {
                    if ($data['type']) {
                        $file = new File();
                        $file->file_name = $data['name'];
                        $file->modal()->associate($topic);

                        if ($data['type'] == "1") {
                            $file->type = 1;
                            $file->file_url = $data['file']->store('lessons', 'public');
                        } elseif ($data['type'] == "2") {
                            $file->type = 2;
                            $file->file_thumbnail = $data['thumbnail']->store('lessons', 'public');
                            $file->file_url = $data['link'];
                        } elseif ($data['type'] == "3") {
                            $file->type = 3;
                            $file->file_thumbnail = $data['thumbnail']->store('lessons', 'public');
                            $file->file_url = $data['file']->store('lessons', 'public');
                        } elseif ($data['type'] == "other_link") {
                            $file->type = 4;
                            $file->file_thumbnail = $data['thumbnail']->store('lessons', 'public');
                            $file->file_url = $data['link'];
                        }

                        $file->save();
                    }
                }
            }

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function updateTopic(Request $request) {
        ResponseService::noPermissionThenSendJson('topic-edit');
        $validator = Validator::make($request->all(), [
            'topic_id'         => 'required|numeric',
            'name'             => 'required',
            'description'      => 'required',
            'class_section_id' => 'required|numeric',
            'subject_id'       => 'required|numeric',
            'edit_file'        => 'nullable|array',
            'edit_file.*.type' => 'nullable|in:1,2,3,4',
            'edit_file.*.name' => 'required_with:edit_file.*.type',
            'edit_file.*.link' => 'required_if:edit_file.*.type,2,',
            'file'             => 'nullable|array',
            'file.*.type'      => 'nullable|in:1,2,3,4',
            'file.*.name'      => 'required_with:file.*.type',
            'file.*.thumbnail' => 'required_if:file.*.type,2,3,4',
            'file.*.file'      => 'required_if:file.*.type,1,3',
            'file.*.link'      => 'required_if:file.*.type,2,4',


            //            'edit_file' => 'nullable|array',
            //            'edit_file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
            //            'edit_file.*.name' => 'required_with:edit_file.*.type',
            //            'edit_file.*.link' => 'required_if:edit_file.*.type,youtube_link,',
            //
            //            'file' => 'nullable|array',
            //            'file.*.type' => 'nullable|in:file_upload,youtube_link,video_upload,other_link',
            //            'file.*.name' => 'required_with:file.*.type',
            //            'file.*.thumbnail' => 'required_if:file.*.type,youtube_link,video_upload,other_link',
            //            'file.*.file' => 'required_if:file.*.type,file_upload,video_upload',
            //            'file.*.link' => 'required_if:file.*.type,youtube_link,other_link',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        $validator2 = Validator::make($request->all(), [
            'name' => [
                'required',
                new uniqueTopicInLesson($request->lesson_id, $request->topic_id)
            ],
        ]);
        if ($validator2->fails()) {
            ResponseService::errorResponse($validator2->errors()->first(), null, config('constants.RESPONSE_CODE.NOT_UNIQUE_IN_CLASS'));
        }
        try {
            $topic = LessonTopic::find($request->topic_id);

            $topic->name = $request->name;
            $topic->description = $request->description;
            $topic->save();

            // Update the Old Files
            if ($request->edit_file) {
                foreach ($request->edit_file as $file) {
                    if ($file['type']) {
                        $topic_file = File::find($file['id']);
                        $topic_file->file_name = $file['name'];

                        if ($file['type'] == "1") {
                            // Type File :- File Upload
                            $topic_file->type = 1;
                            if (!empty($file['file'])) {
                                if (Storage::disk('public')->exists($topic_file->getRawOriginal('file_url'))) {
                                    Storage::disk('public')->delete($topic_file->getRawOriginal('file_url'));
                                }
                                $topic_file->file_url = $file['file']->store('lessons', 'public');
                            }
                        } elseif ($file['type'] == "2") {
                            // Type File :- YouTube Link Upload
                            $topic_file->type = 2;
                            if (!empty($file['thumbnail'])) {
                                if (Storage::disk('public')->exists($topic_file->getRawOriginal('file_url'))) {
                                    Storage::disk('public')->delete($topic_file->getRawOriginal('file_url'));
                                }
                                $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            }

                            $topic_file->file_url = $file['link'];
                        } elseif ($file['type'] == "3") {
                            // Type File :- Video Upload
                            $topic_file->type = 3;
                            if (!empty($file['file'])) {
                                if (Storage::disk('public')->exists($topic_file->getRawOriginal('file_url'))) {
                                    Storage::disk('public')->delete($topic_file->getRawOriginal('file_url'));
                                }
                                $topic_file->file_url = $file['file']->store('lessons', 'public');
                            }

                            if (!empty($file['thumbnail'])) {
                                if (Storage::disk('public')->exists($topic_file->getRawOriginal('file_url'))) {
                                    Storage::disk('public')->delete($topic_file->getRawOriginal('file_url'));
                                }
                                $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            }
                        } elseif ($file['type'] == "4") {
                            $topic_file->type = 4;
                            if (!empty($file['thumbnail'])) {
                                if (Storage::disk('public')->exists($topic_file->getRawOriginal('file_url'))) {
                                    Storage::disk('public')->delete($topic_file->getRawOriginal('file_url'));
                                }
                                $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                            }
                            $topic_file->file_url = $file['link'];
                        }

                        $topic_file->save();
                    }
                }
            }

            //Add the new Files
            if ($request->file) {
                foreach ($request->file as $file) {
                    $topic_file = new File();
                    $topic_file->file_name = $file['name'];
                    $topic_file->modal()->associate($topic);

                    if ($file['type'] == "1") {
                        $topic_file->type = 1;
                        $topic_file->file_url = $file['file']->store('lessons', 'public');
                    } elseif ($file['type'] == "2") {
                        $topic_file->type = 2;
                        $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                        $topic_file->file_url = $file['link'];
                    } elseif ($file['type'] == "3") {
                        $topic_file->type = 3;
                        $topic_file->file_url = $file['file']->store('lessons', 'public');
                        $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                    } elseif ($file['type'] == "4") {
                        $topic_file->type = 4;
                        $topic_file->file_thumbnail = $file['thumbnail']->store('lessons', 'public');
                        $topic_file->file_url = $file['link'];
                    }
                    $topic_file->save();
                }
            }

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function deleteTopic(Request $request) {
        ResponseService::noPermissionThenSendJson('topic-delete');
        try {
            $topic = LessonTopic::LessonTopicTeachers()->findOrFail($request->topic_id);
            $topic->delete();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function updateFile(Request $request) {
        $validator = Validator::make($request->all(), ['file_id' => 'required|numeric',]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $file = File::find($request->file_id);
            $file->file_name = $request->name;


            if ($file->type == "1") {
                // Type File :- File Upload

                if (!empty($request->file)) {
                    if (Storage::disk('public')->exists($file->getRawOriginal('file_url'))) {
                        Storage::disk('public')->delete($file->getRawOriginal('file_url'));
                    }

                    if ($file->modal_type == "App\Models\Lesson") {

                        $file->file_url = $request->file->store('lessons', 'public');
                    } else if ($file->modal_type == "App\Models\LessonTopic") {

                        $file->file_url = $request->file->store('topics', 'public');
                    } else {

                        $file->file_url = $request->file->store('other', 'public');
                    }
                }
            } elseif ($file->type == "2") {
                // Type File :- YouTube Link Upload

                if (!empty($request->thumbnail)) {
                    if (Storage::disk('public')->exists($file->getRawOriginal('file_url'))) {
                        Storage::disk('public')->delete($file->getRawOriginal('file_url'));
                    }

                    if ($file->modal_type == "App\Models\Lesson") {

                        $file->file_thumbnail = $request->thumbnail->store('lessons', 'public');
                    } else if ($file->modal_type == "App\Models\LessonTopic") {

                        $file->file_thumbnail = $request->thumbnail->store('topics', 'public');
                    } else {

                        $file->file_thumbnail = $request->thumbnail->store('other', 'public');
                    }
                }
                $file->file_url = $request->link;
            } elseif ($file->type == "3") {
                // Type File :- Video Upload

                if (!empty($request->file)) {
                    if (Storage::disk('public')->exists($file->getRawOriginal('file_url'))) {
                        Storage::disk('public')->delete($file->getRawOriginal('file_url'));
                    }

                    if ($file->modal_type == "App\Models\Lesson") {

                        $file->file_url = $request->file->store('lessons', 'public');
                    } else if ($file->modal_type == "App\Models\LessonTopic") {

                        $file->file_url = $request->file->store('topics', 'public');
                    } else {

                        $file->file_url = $request->file->store('other', 'public');
                    }
                }

                if (!empty($request->thumbnail)) {
                    if (Storage::disk('public')->exists($file->getRawOriginal('file_url'))) {
                        Storage::disk('public')->delete($file->getRawOriginal('file_url'));
                    }
                    if ($file->modal_type == "App\Models\Lesson") {

                        $file->file_thumbnail = $request->thumbnail->store('lessons', 'public');
                    } else if ($file->modal_type == "App\Models\LessonTopic") {

                        $file->file_thumbnail = $request->thumbnail->store('topics', 'public');
                    } else {

                        $file->file_thumbnail = $request->thumbnail->store('other', 'public');
                    }
                }
            }
            $file->save();

            ResponseService::successResponse('Data Stored Successfully', $file);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function deleteFile(Request $request) {
        $validator = Validator::make($request->all(), ['file_id' => 'required|numeric',]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $file = File::findOrFail($request->file_id);
            $file->delete();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getAnnouncement(Request $request) {
        ResponseService::noPermissionThenSendJson('announcement-list');
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'nullable|numeric',
            'subject_id'       => 'nullable|numeric',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $teacher = Auth::user()->teacher;
            $subject_teacher_ids = SubjectTeacher::where('teacher_id', $teacher->id);
            if ($request->class_section_id) {
                $subject_teacher_ids = $subject_teacher_ids->where('class_section_id', $request->class_section_id);
            }
            if ($request->subject_id) {
                $subject_teacher_ids = $subject_teacher_ids->where('subject_id', $request->subject_id);
            }
            $subject_teacher_ids = $subject_teacher_ids->get()->pluck('id');
            $sql = Announcement::with('table.subject', 'file')->where('table_type', 'App\Models\SubjectTeacher')->whereIn('table_id', $subject_teacher_ids);

            $data = $sql->orderBy('id', 'DESC')->paginate();
            ResponseService::successResponse('Announcement Fetched Successfully.', $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function sendAnnouncement(Request $request) {
        ResponseService::noPermissionThenSendJson('announcement-create');
        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required|numeric',
            'subject_id'       => 'required|numeric',
            'title'            => 'required',
            'description'      => 'nullable',
            'file'             => 'nullable'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $session_year_id = getSystemSettings('session_year');
            $teacher_id = Auth::user()->teacher->id;
            $announcement = new Announcement();
            $announcement->title = $request->title;
            $announcement->description = $request->description;
            $announcement->session_year_id = $session_year_id;

            $subject_teacher = SubjectTeacher::where([
                'teacher_id'       => $teacher_id,
                'class_section_id' => $request->class_section_id,
                'subject_id'       => $request->subject_id
            ])->with('subject')->firstOrFail();
            if ($subject_teacher) {
                $announcement->table()->associate($subject_teacher);
            }
            $user = Students::select('user_id')->where('class_section_id', $request->class_section_id)->get()->pluck('user_id');


            $title = 'New announcement in ' . $subject_teacher->subject->name;
            $body = $request->title;
            $announcement->save();
            send_notification($user, $title, $body, 'class_section');
            if ($request->hasFile('file')) {
                foreach ($request->file as $file_upload) {
                    $file = new File();
                    $file->file_name = $file_upload->getClientOriginalName();
                    $file->type = 1;
                    $file->file_url = $file_upload->store('announcement', 'public');
                    $file->modal()->associate($announcement);
                    $file->save();
                }
            }
            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function updateAnnouncement(Request $request) {
        ResponseService::noPermissionThenSendJson('announcement-edit');
        $validator = Validator::make($request->all(), [
            'announcement_id'  => 'required|numeric',
            'class_section_id' => 'required|numeric',
            'subject_id'       => 'required|numeric',
            'title'            => 'required'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $teacher_id = Auth::user()->teacher->id;
            $announcement = Announcement::findOrFail($request->announcement_id);
            $announcement->title = $request->title;
            $announcement->description = $request->description;

            $subject_teacher = SubjectTeacher::where([
                'teacher_id'       => $teacher_id,
                'class_section_id' => $request->class_section_id,
                'subject_id'       => $request->subject_id
            ])->with('subject')->firstOrFail();
            $announcement->table()->associate($subject_teacher);
            $user = Students::select('user_id')->where('class_section_id', $request->class_section_id)->get()->pluck('user_id');

            $title = 'Update announcement in ' . $subject_teacher->subject->name;
            $body = $request->title;
            $announcement->save();
            send_notification($user, $title, $body, 'class_section');
            if ($request->hasFile('file')) {
                foreach ($request->file as $file_upload) {
                    $file = new File();
                    $file->file_name = $file_upload->getClientOriginalName();
                    $file->type = 1;
                    $file->file_url = $file_upload->store('announcement', 'public');
                    $file->modal()->associate($announcement);
                    $file->save();
                }
            }
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function deleteAnnouncement(Request $request) {
        ResponseService::noPermissionThenSendJson('announcement-delete');
        $validator = Validator::make($request->all(), ['announcement_id' => 'required|numeric',]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $announcement = Announcement::findorFail($request->announcement_id);
            $announcement->delete();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getAttendance(Request $request) {
        ResponseService::noPermissionThenSendJson('attendance-list');
        $class_section_id = $request->class_section_id;
        $attendance_type = $request->type;
        $date = date('Y-m-d', strtotime($request->date));

        $validator = Validator::make($request->all(), [
            'class_section_id' => 'required',
            'date'             => 'required|date',
            'type'             => 'in:0,1',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError();
        }
        try {
            $sql = Attendance::where('class_section_id', $class_section_id)->where('date', $date);
            if (isset($attendance_type) && $attendance_type != '') {
                $sql->where('type', $attendance_type);
            }
            $data = $sql->get();
            $holiday = Holiday::where('date', $date)->get();
            if ($holiday->count()) {
                ResponseService::successResponse("Data Fetched Successfully", $data, [
                    'is_holiday' => true,
                    'holiday'    => $holiday,
                ]);
            } else if ($data->count()) {
                ResponseService::successResponse("Data Fetched Successfully", $data, ['is_holiday' => false]);
            } else {
                ResponseService::successResponse("Attendance not recorded", $data, ['is_holiday' => false]);
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }


    public function submitAttendance(Request $request) {
        ResponseService::noAnyPermissionThenSendJson([
            'attendance-create',
            'attendance-edit'
        ]);
        $validator = Validator::make($request->all(), [
            'class_section_id'        => 'required',
            // 'student_id' => 'required',
            'attendance.*.student_id' => 'required',
            'attendance.*.type'       => 'required|in:0,1',
            'date'                    => 'required|date',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError();
        }
        try {
            $session_year_id = getSystemSettings('session_year');
            $class_section_id = $request->class_section_id;
            $date = date('Y-m-d', strtotime($request->date));
            $getid = Attendance::select('id')->where([
                'date'             => $date,
                'class_section_id' => $class_section_id
            ])->get()->toArray();
            for ($i = 0, $iMax = count($request->attendance); $i < $iMax; $i++) {

                if (count($getid) > 0 && isset($getid[$i]['id'])) {
                    $attendance = Attendance::find($getid[$i]['id']);
                } else {
                    $attendance = new Attendance();
                }


                $std_id = $request->attendance[$i]['student_id'];
                $type = $request->attendance[$i]['type'];
                $attendance->class_section_id = $class_section_id;
                $attendance->student_id = $std_id;
                $attendance->session_year_id = $session_year_id;
                if ($request->holiday != '' && $request->holiday == 3) {
                    $attendance->type = $request->holiday;
                } else {
                    $attendance->type = $type;
                }

                $attendance->date = $date;
                $attendance->save();

                ResponseService::successResponse('Data Stored Successfully');
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getStudentList(Request $request) {
        $validator = Validator::make($request->all(), ['class_section_id' => 'required|numeric',]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $user = Auth::user()->teacher;
            $class_section_id = $request->class_section_id;
            $get_class_section_id = ClassSection::select('id')->where('id', $class_section_id)->where('class_teacher_id', $user->id)->get()->pluck('id');
            $sql = Students::with('user:id,first_name,last_name,image,gender,dob,current_address,permanent_address', 'class_section')->whereIn('class_section_id', $get_class_section_id);
            $data = $sql->orderBy('id')->get();

            ResponseService::successResponse("Student Details Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getStudentDetails(Request $request) {
        $validator = Validator::make($request->all(), ['student_id' => 'required|numeric',]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $student_data_ids = Students::select(['user_id', 'class_section_id', 'guardian_id'])->where('id', $request->student_id)->get();
            $student_total_present = Attendance::where('student_id', $request->student_id)->where('type', 1)->count();
            $student_total_absent = Attendance::where('student_id', $request->student_id)->where('type', 0)->count();

            $today_date_string = Carbon::now();
            $today_date_string->toDateTimeString();
            $today_date = date('Y-m-d', strtotime($today_date_string));

            $student_today_attendance = Attendance::where('student_id', $request->student_id)->where('date', $today_date)->get();
            if ($student_today_attendance->count()) {
                foreach ($student_today_attendance as $student_attendance) {
                    if ($student_attendance['type'] == 1) {
                        $today_attendance = 'Present';
                    } else {
                        $today_attendance = 'Absent';
                    }
                }
            } else {
                $today_attendance = 'Not Taken';
            }
            foreach ($student_data_ids as $row) {
//                $guardian_data = Parents::where('id', $row['guardian_id'])->get();
                ResponseService::successResponse("Student Details Fetched Successfully", null, [
//                    'guardian_data'    => $guardian_data,
'total_present'    => $student_total_present,
'total_absent'     => $student_total_absent,
'today_attendance' => $today_attendance ?? ''
                ]);
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getTeacherTimetable(Request $request) {
        try {
            $teacher = $request->user()->teacher;
            $timetable = Timetable::where('subject_teacher_id', $teacher->id)->with('class_section', 'subject')->get();
            ResponseService::successResponse("Timetable Fetched Successfully", $timetable);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function submitExamMarksBySubjects(Request $request) {
        $validator = Validator::make($request->all(), [
            'exam_id'    => 'required|numeric',
            'subject_id' => 'required|numeric',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $exam_published = Exam::where([
                'id'      => $request->exam_id,
                'publish' => 1
            ])->first();
            if (isset($exam_published)) {
                ResponseService::errorResponse('exam_published', null, config('constants.RESPONSE_CODE.EXAM_ALREADY_PUBLISHED'));
            }

            $teacher_id = Auth::user()->teacher->id;
            $class_id = ClassSection::where('class_teacher_id', $teacher_id)->pluck('class_id')->first();

            //check exam status
            $starting_date_db = ExamTimetable::select(DB::raw("min(date)"))->where([
                'exam_id'  => $request->exam_id,
                'class_id' => $class_id
            ])->first();
            $starting_date = $starting_date_db['min(date)'];
            $ending_date_db = ExamTimetable::select(DB::raw("max(date)"))->where([
                'exam_id'  => $request->exam_id,
                'class_id' => $class_id
            ])->first();
            $ending_date = $ending_date_db['max(date)'];
            $currentTime = Carbon::now();
            $current_date = date($currentTime->toDateString());
            if ($current_date >= $starting_date && $current_date <= $ending_date) {
                $exam_status = "1"; // Upcoming = 0 , On Going = 1 , Completed = 2
            } elseif ($current_date < $starting_date) {
                $exam_status = "0"; // Upcoming = 0 , On Going = 1 , Completed = 2
            } else {
                $exam_status = "2"; // Upcoming = 0 , On Going = 1 , Completed = 2
            }
            if ($exam_status != 2) {
                ResponseService::errorResponse('exam_not_completed_yet', null, config('constants.RESPONSE_CODE.EXAM_ALREADY_PUBLISHED'));
            } else {
//                $grades = Grade::orderBy('ending_range', 'desc')->get();
                $exam_timetable = ExamTimetable::where('exam_id', $request->exam_id)->where('subject_id', $request->subject_id)->firstOrFail();
                foreach ($request->marks_data as $marks) {
                    $passing_marks = $exam_timetable->passing_marks;
                    if ($marks['obtained_marks'] >= $passing_marks) {
                        $status = 1;
                    } else {
                        $status = 0;
                    }
                    $marks_percentage = ($marks['obtained_marks'] / $exam_timetable['total_marks']) * 100;

                    $exam_grade = findExamGrade($marks_percentage);
                    if ($exam_grade == null) {
                        ResponseService::errorResponse('grades_data_does_not_exists', null, config('constants.RESPONSE_CODE.GRADES_NOT_FOUND'));
                    }

                    $exam_marks = ExamMarks::where([
                        'exam_timetable_id' => $exam_timetable->id,
                        'subject_id'        => $request->subject_id,
                        'student_id'        => $marks['student_id']
                    ])->first();
                    if ($exam_marks) {
                        $exam_marks_db = ExamMarks::find($exam_marks->id);
                        $exam_marks_db->obtained_marks = $marks['obtained_marks'];
                        $exam_marks_db->passing_status = $status;
                        $exam_marks_db->grade = $exam_grade;
                        $exam_marks_db->save();

                        ResponseService::successResponse('Data Updated Successfully');
                    } else {
                        $exam_result_marks[] = array(
                            'exam_timetable_id' => $exam_timetable->id,
                            'student_id'        => $marks['student_id'],
                            'subject_id'        => $request->subject_id,
                            'obtained_marks'    => $marks['obtained_marks'],
                            'passing_status'    => $status,
                            'session_year_id'   => $exam_timetable->session_year_id,
                            'grade'             => $exam_grade,
                        );
                    }
                }
                if (isset($exam_result_marks)) {
                    ExamMarks::insert($exam_result_marks);
                    ResponseService::successResponse('Data Stored Successfully');
                }
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }


    public function submitExamMarksByStudent(Request $request) {
        $validator = Validator::make($request->all(), [
            'exam_id'    => 'required|numeric',
            'student_id' => 'required|numeric',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $exam_published = Exam::where([
                'id'      => $request->exam_id,
                'publish' => 1
            ])->first();
            if (isset($exam_published)) {

                ResponseService::errorResponse('exam_published', null, config('constants.RESPONSE_CODE.EXAM_ALREADY_PUBLISHED'));
            }

            $teacher_id = Auth::user()->teacher->id;
            $class_id = ClassSection::where('class_teacher_id', $teacher_id)->pluck('class_id')->first();

            //exam status
            $starting_date_db = ExamTimetable::select(DB::raw("min(date)"))->where([
                'exam_id'  => $request->exam_id,
                'class_id' => $class_id
            ])->first();
            $starting_date = $starting_date_db['min(date)'];
            $ending_date_db = ExamTimetable::select(DB::raw("max(date)"))->where([
                'exam_id'  => $request->exam_id,
                'class_id' => $class_id
            ])->first();
            $ending_date = $ending_date_db['max(date)'];
            $currentTime = Carbon::now();
            $current_date = date($currentTime->toDateString());
            if ($current_date >= $starting_date && $current_date <= $ending_date) {
                $exam_status = "1"; // Upcoming = 0 , On Going = 1 , Completed = 2
            } elseif ($current_date < $starting_date) {
                $exam_status = "0"; // Upcoming = 0 , On Going = 1 , Completed = 2
            } else {
                $exam_status = "2"; // Upcoming = 0 , On Going = 1 , Completed = 2
            }

            if ($exam_status != 2) {
                ResponseService::errorResponse('exam_published', null, config('constants.RESPONSE_CODE.EXAM_NOT_COMPLETED'));
            } else {
//                $grades = Grade::orderBy('ending_range', 'desc')->get();

                foreach ($request->marks_data as $marks) {
                    $exam_timetable = ExamTimetable::where([
                        'exam_id'    => $request->exam_id,
                        'subject_id' => $marks['subject_id']
                    ])->firstOrFail();
                    $passing_marks = $exam_timetable->passing_marks;
                    if ($marks['obtained_marks'] >= $passing_marks) {
                        $status = 1;
                    } else {
                        $status = 0;
                    }
                    $marks_percentage = ($marks['obtained_marks'] / $exam_timetable->total_marks) * 100;

                    $exam_grade = findExamGrade($marks_percentage);
                    if ($exam_grade == null) {
                        ResponseService::errorResponse('grades_data_does_not_exists', null, config('constants.RESPONSE_CODE.GRADES_NOT_FOUND'));
                    }

                    $exam_marks = ExamMarks::where([
                        'exam_timetable_id' => $exam_timetable->id,
                        'student_id'        => $request->student_id,
                        'subject_id'        => $marks['subject_id']
                    ])->first();
                    if ($exam_marks) {
                        $exam_marks_db = ExamMarks::find($exam_marks->id);
                        $exam_marks_db->obtained_marks = $marks['obtained_marks'];
                        $exam_marks_db->passing_status = $status;
                        $exam_marks_db->grade = $exam_grade;
                        $exam_marks_db->save();

                        ResponseService::successResponse('Data Updated Successfully');
                    } else {
                        $exam_result_marks[] = array(
                            'exam_timetable_id' => $exam_timetable->id,
                            'student_id'        => $request->student_id,
                            'subject_id'        => $marks['subject_id'],
                            'obtained_marks'    => $marks['obtained_marks'],
                            'passing_status'    => $status,
                            'session_year_id'   => $exam_timetable->session_year_id,
                            'grade'             => $exam_grade,
                        );
                    }
                }
                if (isset($exam_result_marks)) {
                    ExamMarks::insert($exam_result_marks);
                    ResponseService::successResponse('Data Stored Successfully');
                }
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }


    public function GetStudentExamResult(Request $request) {
        $validator = Validator::make($request->all(), ['student_id' => 'required|nullable']);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $teacher_id = Auth::user()->teacher->id;
            $class_data = ClassSection::where('class_teacher_id', $teacher_id)->with('class', 'section', 'medium')->get()->first();

            $exam_marks_db = ExamClass::with([
                'exam.timetable' => function ($q) use ($request, $class_data) {
                    $q->where('class_id', $class_data->class_id)->with([
                        'exam_marks' => function ($q) use ($request) {
                            $q->where('student_id', $request->student_id);
                        }
                    ])->with('subject:id,name,type,image,code');
                }
            ])->with([
                'exam.results' => function ($q) use ($request) {
                    $q->where('student_id', $request->student_id)->with([
                        'student' => function ($q) {
                            $q->select('id', 'user_id', 'roll_number')->with('user:id,first_name,last_name');
                        }
                    ])->with('session_year:id,name');
                }
            ])->where('class_id', $class_data->class_id)->get();

            if (count($exam_marks_db)) {
                foreach ($exam_marks_db as $data_db) {
                    $starting_date_db = ExamTimetable::select(DB::raw("min(date)"))->where([
                        'exam_id'  => $data_db->exam_id,
                        'class_id' => $class_data->class_id
                    ])->first();
                    $starting_date = $starting_date_db['min(date)'];
                    $ending_date_db = ExamTimetable::select(DB::raw("max(date)"))->where([
                        'exam_id'  => $data_db->exam_id,
                        'class_id' => $class_data->class_id
                    ])->first();
                    $ending_date = $ending_date_db['max(date)'];
                    $currentTime = Carbon::now();
                    $current_date = date($currentTime->toDateString());
                    if ($current_date >= $starting_date && $current_date <= $ending_date) {
                        $exam_status = "1"; // Upcoming = 0 , On Going = 1 , Completed = 2
                    } elseif ($current_date < $starting_date) {
                        $exam_status = "0"; // Upcoming = 0 , On Going = 1 , Completed = 2
                    } else {
                        $exam_status = "2"; // Upcoming = 0 , On Going = 1 , Completed = 2
                    }

                    // check whether exam is completed or not
                    if ($exam_status == 2) {
                        $marks_array = array();

                        // check whether timetable exists or not
                        if (count($data_db->exam->timetable)) {
                            foreach ($data_db->exam->timetable as $timetable_db) {
                                $total_marks = $timetable_db->total_marks;
                                $exam_marks = array();
                                if (count($timetable_db->exam_marks)) {
                                    foreach ($timetable_db->exam_marks as $marks_data) {
                                        $exam_marks = array(
                                            'marks_id'       => $marks_data->id,
                                            'subject_name'   => $marks_data->subject->name,
                                            'subject_type'   => $marks_data->subject->type,
                                            'total_marks'    => $total_marks,
                                            'obtained_marks' => $marks_data->obtained_marks,
                                            'grade'          => $marks_data->grade,
                                        );
                                    }
                                } else {
                                    $exam_marks = (object)[];
                                }

                                $marks_array[] = array(
                                    'subject_id'   => $timetable_db->subject->id,
                                    'subject_name' => $timetable_db->subject->name,
                                    'subject_type' => $timetable_db->subject->type,
                                    'total_marks'  => $total_marks,
                                    'subject_code' => $timetable_db->subject->code,
                                    'marks'        => $exam_marks
                                );
                            }
                            $exam_result = array();
                            if (count($data_db->exam->results)) {
                                foreach ($data_db->exam->results as $result_data) {
                                    $exam_result = array(
                                        'result_id'      => $result_data->id,
                                        'exam_id'        => $result_data->exam_id,
                                        'exam_name'      => $data_db->exam->name,
                                        'class_name'     => $class_data->class->name . '-' . $class_data->section->name . ' ' . $class_data->class->medium->name,
                                        'student_name'   => $result_data->student->user->first_name . ' ' . $result_data->student->user->last_name,
                                        'exam_date'      => $starting_date,
                                        'total_marks'    => $result_data->total_marks,
                                        'obtained_marks' => $result_data->obtained_marks,
                                        'percentage'     => $result_data->percentage,
                                        'grade'          => $result_data->grade,
                                        'session_year'   => $result_data->session_year->name,
                                    );
                                }
                            } else {
                                $exam_result = (object)[];
                            }
                            $data[] = array(
                                'exam_id'    => $data_db->exam_id,
                                'exam_name'  => $data_db->exam->name,
                                'exam_date'  => $starting_date,
                                'marks_data' => $marks_array,
                                'result'     => $exam_result
                            );
                        }
                    }
                }
                ResponseService::successResponse("Exam Marks Fetched Successfully", $data ?? []);
            } else {
                ResponseService::successResponse("Exam Marks Fetched Successfully", []);
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function GetStudentExamMarks(Request $request) {
        $validator = Validator::make($request->all(), ['student_id' => 'required|nullable']);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $teacher_id = Auth::user()->teacher->id;
            $class_data = ClassSection::where('class_teacher_id', $teacher_id)->with('class', 'section', 'medium')->get()->first();

            $exam_marks_db = ExamClass::with([
                'exam.timetable' => function ($q) use ($request, $class_data) {
                    $q->where('class_id', $class_data->class_id)->with([
                        'exam_marks' => function ($q) use ($request) {
                            $q->where('student_id', $request->student_id);
                        }
                    ])->with('subject:id,name,type,image');
                }
            ])->where('class_id', $class_data->class_id)->get();

            if (count($exam_marks_db)) {
                foreach ($exam_marks_db as $data_db) {
                    $marks_array = array();
                    foreach ($data_db->exam->timetable as $marks_db) {
                        $exam_marks = array();
                        if (count($marks_db->exam_marks)) {
                            foreach ($marks_db->exam_marks as $marks_data) {
                                $exam_marks = array(
                                    'marks_id'       => $marks_data->id,
                                    'subject_name'   => $marks_data->subject->name,
                                    'subject_type'   => $marks_data->subject->type,
                                    'total_marks'    => $marks_data->timetable->total_marks,
                                    'obtained_marks' => $marks_data->obtained_marks,
                                    'grade'          => $marks_data->grade,
                                );
                            }
                        } else {
                            $exam_marks = [];
                        }

                        $marks_array[] = array(
                            'subject_id'   => $marks_db->subject->id,
                            'subject_name' => $marks_db->subject->name,
                            'marks'        => $exam_marks
                        );
                    }
                    $data[] = array(
                        'exam_id'    => $data_db->exam_id,
                        'exam_name'  => $marks_db->exam->name ?? '',
                        'marks_data' => $marks_array
                    );
                }
                ResponseService::successResponse("Exam Marks Fetched Successfully", $data ?? '');
            } else {
                ResponseService::successResponse("Exam Marks Fetched Successfully", []);
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getExamList(Request $request) {
        $validator = Validator::make($request->all(), [
            'status'  => 'in:0,1,2,3',
            'publish' => 'in:0,1',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $teacher = Auth::user()->teacher;
            $teacher_class = ClassSection::with('class')->where('class_teacher_id', $teacher->id)->first();
            $class_id = $teacher_class->class->id;
            $sql = ExamClass::with('exam.session_year:id,name')->where('class_id', $class_id);
            if (isset($request->publish)) {
                $publish = $request->publish;
                $sql->whereHas('exam', function ($q) use ($publish) {
                    $q->where('publish', $publish);
                });
            }
            $exam_data_db = $sql->get();
            foreach ($exam_data_db as $data) {
                // date status
                $starting_date_db = ExamTimetable::select(DB::raw("min(date)"))->where([
                    'exam_id'  => $data->exam_id,
                    'class_id' => $class_id
                ])->first();
                $starting_date = $starting_date_db['min(date)'];

                $ending_date_db = ExamTimetable::select(DB::raw("max(date)"))->where([
                    'exam_id'  => $data->exam_id,
                    'class_id' => $class_id
                ])->first();
                $ending_date = $ending_date_db['max(date)'];

                $currentTime = Carbon::now();
                $current_date = date($currentTime->toDateString());
                if ($current_date >= $starting_date && $current_date <= $ending_date) {
                    $exam_status = "1"; // Upcoming = 0 , On Going = 1 , Completed = 2
                } elseif ($current_date < $starting_date) {
                    $exam_status = "0"; // Upcoming = 0 , On Going = 1 , Completed = 2
                } else {
                    $exam_status = "2"; // Upcoming = 0 , On Going = 1 , Completed = 2
                }

                // $request->status  =  0 :- all exams , 1 :- Upcoming , 2 :- On Going , 3 :- Completed

                if (isset($request->status)) {
                    if ($request->status == 0) {
                        $exam_data[] = array(
                            'id'                 => $data->exam->id,
                            'name'               => $data->exam->name,
                            'description'        => $data->exam->description,
                            'publish'            => $data->exam->publish,
                            'session_year'       => $data->exam->session_year->name,
                            'exam_starting_date' => $starting_date,
                            'exam_ending_date'   => $ending_date,
                            'exam_status'        => $exam_status,
                        );
                    } else if ($request->status == 1) {
                        if ($exam_status == 0) {
                            $exam_data[] = array(
                                'id'                 => $data->exam->id,
                                'name'               => $data->exam->name,
                                'description'        => $data->exam->description,
                                'publish'            => $data->exam->publish,
                                'session_year'       => $data->exam->session_year->name,
                                'exam_starting_date' => $starting_date,
                                'exam_ending_date'   => $ending_date,
                                'exam_status'        => $exam_status,
                            );
                        }
                    } else if ($request->status == 2) {
                        if ($exam_status == 1) {
                            $exam_data[] = array(
                                'id'                 => $data->exam->id,
                                'name'               => $data->exam->name,
                                'description'        => $data->exam->description,
                                'publish'            => $data->exam->publish,
                                'session_year'       => $data->exam->session_year->name,
                                'exam_starting_date' => $starting_date,
                                'exam_ending_date'   => $ending_date,
                                'exam_status'        => $exam_status,
                            );
                        }
                    } else if ($exam_status == 2) {
                        $exam_data[] = array(
                            'id'                 => $data->exam->id,
                            'name'               => $data->exam->name,
                            'description'        => $data->exam->description,
                            'publish'            => $data->exam->publish,
                            'session_year'       => $data->exam->session_year->name,
                            'exam_starting_date' => $starting_date,
                            'exam_ending_date'   => $ending_date,
                            'exam_status'        => $exam_status,
                        );
                    }
                } else {
                    $exam_data[] = array(
                        'id'                 => $data->exam->id,
                        'name'               => $data->exam->name,
                        'description'        => $data->exam->description,
                        'publish'            => $data->exam->publish,
                        'session_year'       => $data->exam->session_year->name,
                        'exam_starting_date' => $starting_date,
                        'exam_ending_date'   => $ending_date,
                        'exam_status'        => $exam_status,
                    );
                }
            }

            ResponseService::successResponse(null, $exam_data ?? []);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getExamDetails(Request $request) {
        $validator = Validator::make($request->all(), ['exam_id' => 'required|nullable',]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $teacher = Auth::user()->teacher;
            $teacher_class = ClassSection::with('class')->where('class_teacher_id', $teacher->id)->first();
            $class_id = $teacher_class->class->id;
            $exam_data = Exam::with([
                'timetable' => function ($q) use ($request, $class_id) {
                    $q->where([
                        'exam_id'  => $request->exam_id,
                        'class_id' => $class_id
                    ])->with('subject');
                }
            ])->where('id', $request->exam_id)->get();
            ResponseService::successResponse(null, $exam_data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }
}
