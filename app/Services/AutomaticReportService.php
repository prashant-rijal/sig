<?php

namespace App\Services;

use App\Jobs\SendAutomaticReport;
use Log;
use Illuminate\Support\Facades\Storage;
use App\Models\ProjectDevice;
use App\Models\ReadingDataPacket;
use App\Libraries\Helpers\PdfHelperReport;
use App\Models\Device;
use App\Models\Project;
use App\Models\ApplicationOption;
use App\Services\AlarmDataService;
use Carbon\Carbon;
use Auth;
use App\Models\User;
use App\Models\AutoReport;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Mail;
use App\Libraries\Helpers\SignatrolHtmlHelper;
use App\Models\Subscription;
use App\Models\CompanySchema;
use DB;

class AutomaticReportService {

    protected $days;

    public function __construct() {
        define('AUTO_REPORT_DIR', public_path() . '/auto-reports/');
    }

    public function generateAutomaticReportBySchema($projectId, $reportType, $params, $schema) {
        $file = $this->getAutomaticReportPDFBySchema($projectId, $reportType, $params, $schema);
        return $file;
    }

    // public function generateAutomaticReportOld($projectId, $reportType, $params) {
    //     $file = $this->getAutomaticReportPDF($projectId, $reportType, $params);
    //     return $file;
    // }
    

    public function sendAutomaticReportBySchema($reportType, $schema) {
        $utcTimeStart = date('H:i:s', strtotime("-5 minutes"));
		// $schema = 'signatrol79724506';
        $utcTimeEnd = date('H:i:s');
		// dd($reportType);
        $utcDay = strtolower(date('l'));
        $projects = $schema.'.'.'projects';
        $application_options = $schema.'.'.'application_options';
        $auto_reports = $schema.'.'.'auto_reports';
        if($reportType=='daily') {
            $projects = DB::table($projects)->whereBetween('utc_report_time', [$utcTimeStart, $utcTimeEnd])
                                ->join($application_options, 'application_options.project_id', '=', 'projects.id')
                                ->select('projects.*','application_options.utc_report_time')
                                ->get();
        } elseif($reportType=='weekly') {
            //weekly reports
            $reportDay = strtolower(date('l'));
            $projects = DB::table($projects)->where('report_day', $reportDay)
                                ->whereBetween('utc_report_time', [$utcTimeStart, $utcTimeEnd])
                                ->join($application_options, 'application_options.project_id', '=', 'projects.id')
                                ->select('projects.*','application_options.utc_report_time')
                                ->get();
        } elseif($reportType=='monthly') {
            //monthly reports
            $projects = DB::table($projects)
                        ->join($application_options, 'application_options.project_id', '=', 'projects.id')->select('projects.*','application_options.utc_report_time')->orderBy('id', 'ASC')->get();
        } else {
            return true;
        }
			// print_r($projects);
        foreach ($projects as $project) {
            $projectId = $project->id;
            $projectObj = new Project;
            $subscription = new Subscription();
            $projectDetails = $projectObj->getProjectDataV2($projectId, $schema);
            $subscription_status = $subscription->getProjectSubscrptionV2($projectId, $schema);
            // get auto reports data the project
            $autoReport = DB::table($auto_reports)->where('project_id', '=', $projectId)
                    ->where('report_type', '=', $reportType)
                    ->where('report_status', '=', 'enabled')
                    ->first();
            if ($autoReport) {
                //generate report
                $lastSent = $autoReport->last_report_at;
                if(!$lastSent){
                    if($reportType == 'daily')
                    $lastSent = Carbon::now()->subDays(1);
                    else if($reportType == 'weekly')
                    $lastSent = Carbon::now()->subWeek(1);
                    else if($reportType == 'monthly')   
                    $lastSent = Carbon::now()->subMonth(1); 
                } else {
                    $lastSent = Carbon::parse($lastSent);
                }
				echo $lastSent."\n";
                $pendingReports = 1;
                if($reportType == 'daily'){
                    $pendingReports = $lastSent->diffInDays(Carbon::parse(date("Y-m-d H:i:s",strtotime("+1 hour"))));
                } else if($reportType == 'weekly'){
                    $pendingReports = $lastSent->diffInWeeks(Carbon::parse(date("Y-m-d H:i:s",strtotime("+1 hour"))));
                } else if($reportType == 'monthly'){
                    $pendingReports = $lastSent->diffInMonths(Carbon::parse(date("Y-m-d H:i:s",strtotime("+1 hour"))));
                }
					// dd($pendingReports);
                for($i = $pendingReports;$i > 0; $i--) {
                    $params =  []; $output = [];
                    if($reportType == 'daily'){
                        $params['startDate'] = date('d/m/Y', strtotime('-'.($i).' day'))." ".$project->utc_report_time;
                        $params['endDate'] = date('d/m/Y',strtotime('-'.($i-1).' day'))." ".$project->utc_report_time;
                    }
                    else if($reportType == 'weekly'){
                        $params['startDate'] = date('d/m/Y', strtotime('-'.($i).' week'))." ".$project->utc_report_time;
                        $params['endDate'] = date('d/m/Y',strtotime('-'.(($i-1)).' week'))." ".$project->utc_report_time;
                    }
                    else if($reportType == 'monthly'){
                        $params['startDate'] = date('d/m/Y', strtotime('-'.($i).' month'))." ".$project->utc_report_time;
                        $params['endDate'] = date('d/m/Y',strtotime('-'.($i-1).' month'))." ".$project->utc_report_time;
                    }
                    $file = $this->generateAutomaticReportBySchema($projectId, $reportType, $params, $schema);
                    // send emails to each user at auto reports data
                    $userList = explode(',', $autoReport->user_list);
                    if(count($userList)>0) {
                        foreach ($userList as $userId) {
                            $params = [];
                            if (($userId != null) && (is_numeric($userId)) ) {
                                $userData = User::where('id',$userId)->where('is_deleted', 0)->first();
                                if($userData) {
                                    $data['subject'] = $reportType . " - automatic report";
                                    $data['message'] = "Please find attached ".$reportType." report for project : " . $projectDetails->project_name;
                                    $data['file'] = $file;
                                    $data['fileName'] = 'project-' . $projectDetails->project_name . '-' . $reportType . '-' . 'report.pdf';
                                    $data['firstName'] = $userData->first_name;
                                    $data['lastName'] = $userData->last_name;
                                    $data['email'] = $userData->email;
                                    Log::info('User Id : '.$userId. ' ||  Email :'.$data['email'] );
                                    if($subscription_status->subscription_status != 'suspended') {
                                        event(new \App\Events\SendAutomaticReport($data['subject'], $data['message'], $data['file'], $data['fileName'],
                                            $data['firstName'], $data['lastName'], $data['email']));
                                    }
                                    $output[] = $data;
                                }
                            }
                        }
                        if($reportType=='daily'){
                            \Log::channel('automaticDailylog')->info(\GuzzleHttp\json_encode($output));
                        } else if($reportType=='weekly'){
                            \Log::channel('automaticWeeklylog')->info(\GuzzleHttp\json_encode($output));
                        } else {
                            \Log::channel('automaticMonthlylog')->info(\GuzzleHttp\json_encode($output));
                        }
                    }
                    DB::table("$schema.auto_reports")->update(array('last_report_at'=>Carbon::now()));
                }
            }
        }
        return true;
    }

    public function sendAutomaticReport($reportType) {

        $autoReport = new SendAutomaticReport($reportType);
        dispatch($autoReport);

//        $companySchemas = CompanySchema::orderBy('id', 'ASC')->get()->toArray();
//        $this->convertAndUpdateDate($companySchemas);
//        foreach ($companySchemas as $key => $companySchema) {
//            $schema = $companySchema['schema'];
//			// echo $schema."\n";
//            $response = $this->sendAutomaticReportBySchema($reportType, $schema);
//        }
//        $dateTime = date('Y-m-d H:i:s') . ' => Report sent Successfully';
//        if($reportType=='daily'){
//            \Log::channel('automaticDailylog')->info(\GuzzleHttp\json_encode($dateTime));
//        } else if($reportType=='weekly'){
//            \Log::channel('automaticWeeklylog')->info(\GuzzleHttp\json_encode($dateTime));
//        } else {
//            \Log::channel('automaticMonthlylog')->info(\GuzzleHttp\json_encode($dateTime));
//        }
//        return true;
    }

    public function sendAutomaticReportOld($reportType) {
        //get all projects 
        $utcTimeStart = date('H:i:s', strtotime("-5 minutes"));
        $utcTimeEnd = date('H:i:s');
        $utcDay = strtolower(date('l'));


        
        $companySchemas = CompanySchema::get()->toArray();

        //Converts the report time to correct utc report time per the project timezone.
        // $this->convertAndUpdateDate($companySchemas);

        foreach ($companySchemas as $key => $companySchema) {
            $schema = $companySchema['schema'];
            $response = $this->sendAutomaticReportBySchema($reportType, $schema);
        }

        if($reportType=='daily'){
            //daily reports
            $projects = Project::whereBetween('utc_report_time', [$utcTimeStart, $utcTimeEnd])
                                ->join('application_options', 'application_options.project_id', '=', 'projects.id')
                                ->select('projects.*','application_options.utc_report_time')
                                ->get();
        }else if($reportType=='weekly'){
            //weekly reports
            $reportDay = strtolower(date('l'));
            $projects = Project::where('report_day', $reportDay)
                                ->whereBetween('utc_report_time', [$utcTimeStart, $utcTimeEnd])
                                ->join('application_options', 'application_options.project_id', '=', 'projects.id')
                                ->select('projects.*','application_options.utc_report_time')
                                ->get();
        }else if($reportType=='monthly'){
            //monthly reports
//            $projects = Project::all();
        }else{
            return true;
        }
//        dd($projects);
        //select * from application_options where report_time between '11:40:00' and  '18:40:00'
        
        foreach ($projects as $project) {
            //get project data 
            $projectId = $project->id;
            //$reportType = 'daily';
            $projectObj = new Project;
            $subscription = new Subscription();
            $projectDetails = $projectObj->getProjectData($projectId);
            $subscription_status = $subscription->getProjectSubscrption($projectId);

            // get auto reports data the project
            $autoReport = AutoReport::where('project_id', '=', $projectId)
                    ->where('report_type', '=', $reportType)
                    ->where('report_status', '=', 'enabled')
                    ->first();

            if ($autoReport) {
                //generate report
                $lastSent = $autoReport->last_report_at;
                if(!$lastSent){
                    if($reportType == 'daily')
                    $lastSent = Carbon::now()->subDays(1);
                    else if($reportType == 'weekly')
                    $lastSent = Carbon::now()->subWeek(1);
                    else if($reportType == 'monthly')   
                    $lastSent = Carbon::now()->subMonth(1); 
                }else{
                    $lastSent = Carbon::parse($lastSent);
                }
                $pendingReports = 1;
                if($reportType == 'daily'){
                    $pendingReports = $lastSent->diffInDays(Carbon::parse(date("Y-m-d H:i:s",strtotime("+1 hour"))));
                } else if($reportType == 'weekly'){
                    $pendingReports = $lastSent->diffInWeeks(Carbon::parse(date("Y-m-d H:i:s",strtotime("+1 hour"))));
                } else if($reportType == 'monthly'){
                    $pendingReports = $lastSent->diffInMonths(Carbon::parse(date("Y-m-d H:i:s",strtotime("+1 hour"))));
                }
                for($i = $pendingReports;$i > 0; $i--) {
                $params =  []; $output = [];
                if($reportType == 'daily'){
                    $params['startDate'] = date('d/m/Y', strtotime('-'.($i).' day'))." ".$project->utc_report_time;
                    $params['endDate'] = date('d/m/Y',strtotime('-'.($i-1).' day'))." ".$project->utc_report_time;
                }
                else if($reportType == 'weekly'){
                    $params['startDate'] = date('d/m/Y', strtotime('-'.($i).' week'))." ".$project->utc_report_time;
                    $params['endDate'] = date('d/m/Y',strtotime('-'.(($i-1)).' week'))." ".$project->utc_report_time;
                }
                else if($reportType == 'monthly'){
                    $params['startDate'] = date('d/m/Y', strtotime('-'.($i).' month'))." ".$project->utc_report_time;
                    $params['endDate'] = date('d/m/Y',strtotime('-'.($i-1).' month'))." ".$project->utc_report_time;
                }
                $file = $this->generateAutomaticReport($projectId, $reportType, $params);

                // send emails to each user at auto reports data
                $userList = explode(',', $autoReport->user_list);
                if(count($userList)>0){
                    foreach ($userList as $userId) {
                        $params = [];
                        if (($userId != null) && (is_numeric($userId)) ) {
                            $userData = User::where('id',$userId)->where('is_deleted', 0)->first();
                            if($userData){
                                $data['subject'] = $reportType . " - automatic report";
                                $data['message'] = "Please find attached ".$reportType." report for project : " . $projectDetails->project_name;
                                $data['file'] = $file;
                                $data['fileName'] = 'project-' . $projectDetails->project_name . '-' . $reportType . '-' . 'report.pdf';
                                $data['firstName'] = $userData->first_name;
                                $data['lastName'] = $userData->last_name;
                                $data['email'] = $userData->email;
                                Log::info('User Id : '.$userId. ' ||  Email :'.$data['email'] );
                                if($subscription_status->subscription_status != 'suspended') {
                                    event(new \App\Events\SendAutomaticReport($data['subject'], $data['message'], $data['file'], $data['fileName'],
                                        $data['firstName'], $data['lastName'], $data['email']));
                                }
                                $output[] = $data;
                            }
                       }
                    }
                    if($reportType=='daily'){
                        \Log::channel('automaticDailylog')->info(\GuzzleHttp\json_encode($output));
                    } else if($reportType=='weekly'){
                        \Log::channel('automaticWeeklylog')->info(\GuzzleHttp\json_encode($output));
                    } else {
                        \Log::channel('automaticMonthlylog')->info(\GuzzleHttp\json_encode($output));
                    }
                }
                $autoReport->update(array('last_report_at'=>Carbon::now()));
            }
            }

        }
        $dateTime = date('Y-m-d H:i:s') . ' => Report sent Successfully';
        if($reportType=='daily'){
            \Log::channel('automaticDailylog')->info(\GuzzleHttp\json_encode($dateTime));
        } else if($reportType=='weekly'){
            \Log::channel('automaticWeeklylog')->info(\GuzzleHttp\json_encode($dateTime));
        } else {
            \Log::channel('automaticMonthlylog')->info(\GuzzleHttp\json_encode($dateTime));
        }

        return true;
    }

    public function getAutomaticReportPDFBySchema($projectId, $reportType, $params, $schema) { 
        ini_set('memory_limit', '-1');
        $projectDevice = new ProjectDevice;
        $readingDataPacket = new \App\Models\ReadingDataPacket;
        $pdf = new \App\Libraries\Helpers\PdfHelperReport;
        $device = new Device;
        $project = new Project;
        $alarmDataService = new AlarmDataService;
        $projectDeviceSystemAlarm = new \App\Models\ProjectDeviceSystemAlarm();
        $options = new \App\Models\ApplicationOption();
        $optionsData = $options->getProjectOptionsV2($projectId, $schema);
        $temperatureUnit = 'Celsius';
        if($optionsData){
            $temperatureUnit = $optionsData->temperature_units;
        }
        //get project details
        $projectData = $project->getProjectDataV2($projectId, $schema);
        //Converts the report time to correct utc report time per the project timezone.
        // $this->convertAndUpdateDate();

        if(empty($params)){
            if ($reportType == 'daily') {
                $params['startDate'] = date('d/m/Y', strtotime('-1 day'))." ".$project->utc_report_time;
                $params['endDate'] = date('d/m/Y')." ".$project->utc_report_time;
            } elseif ($reportType == 'weekly') {
                $params['startDate'] = date('d/m/Y', strtotime('-7 days'))." ".$project->utc_report_time;
                $params['endDate'] = date('d/m/Y')." ".$project->utc_report_time;
            } elseif ($reportType == 'monthly') {
                $params['startDate'] = date('d/m/Y', strtotime('-30 days'))." ".$project->utc_report_time;
                $params['endDate'] = date('d/m/Y')." ".$project->utc_report_time;
            }
        }
        $deviceList = $projectDevice->getDeviceListInfoV2($projectId, $schema);
        $transInfo = [];
        $k=0;
        $projectDevicesIds = [];
        foreach($deviceList as $dInfo){
            array_push($projectDevicesIds, $dInfo->project_device_id);
            $readingDataPackets = DB::table("$schema.reading_data_packets")->where(['project_device_id'=>$dInfo->project_device_id])->orderBy('reading_date', 'desc')->select('reading_date','last_calibration_date','remaining_battery_capacity')->first();
            $transInfo[$dInfo->project_device_id]['device_location_name'] = $dInfo->device_location_name;
            $transInfo[$dInfo->project_device_id]['reading_date'] = $readingDataPackets && $dInfo->project_time_zone ? \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($readingDataPackets->reading_date, $dInfo->project_time_zone) : '';
            $transInfo[$dInfo->project_device_id]['last_calibration_date'] =  $readingDataPackets ? $readingDataPackets->last_calibration_date : '';
            $transInfo[$dInfo->project_device_id]['battery_capacity'] = $readingDataPackets ? round($readingDataPackets->remaining_battery_capacity).'%' : '';
            $k++;
        }
        usort($transInfo, function($a, $b) {
            if($a['reading_date'] && $b['reading_date'])
            return $a['reading_date'] < $b['reading_date'];
            else
            return 0;
        });
        $params['projectDevicesIds'] = implode(', ', $projectDevicesIds);
        $params['schema'] = $schema;
        $summaryData = $device->getSummaryDataV2($projectId, $params);
        $sData =[];
        $i=0;
        $index = 1;
        foreach($summaryData as $summData) {
            $sData[$i]['id'] = $index++;
            $sData[$i]['mac_id'] = ($summData->device_location_name!=null) ? $summData->device_location_name:  \App\Libraries\Helpers\SignatrolHtmlHelper::macAddressDecToHex($summData->mac_id);
            $sData[$i]['min1'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimal( $summData->min1, $temperatureUnit, $summData->ch1_enable));
            $sData[$i]['avg1'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimal( $summData->avg1, $temperatureUnit, $summData->ch1_enable));
            $sData[$i]['max1'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimal( $summData->max1, $temperatureUnit, $summData->ch1_enable));
            $sData[$i]['min2'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimalSpecialChannel( $summData->min2, $temperatureUnit, $summData->ch2_enable));
            $sData[$i]['avg2'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimalSpecialChannel( $summData->avg2, $temperatureUnit, $summData->ch2_enable));
            $sData[$i]['max2'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimalSpecialChannel( $summData->max2, $temperatureUnit, $summData->ch2_enable));
            $sData[$i]['min3'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimal( $summData->min3, $temperatureUnit, $summData->ch3_enable));
            $sData[$i]['avg3'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimal( $summData->avg3, $temperatureUnit, $summData->ch3_enable));
            $sData[$i]['max3'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimal( $summData->max3, $temperatureUnit, $summData->ch3_enable));
            $sData[$i]['min4'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimal( $summData->min4, $temperatureUnit, $summData->ch4_enable));
            $sData[$i]['avg4'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimal( $summData->avg4, $temperatureUnit, $summData->ch4_enable));
            $sData[$i]['max4'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateOneDecimal( $summData->max4, $temperatureUnit, $summData->ch4_enable));
            $sData[$i]['min5'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateThreeDecimalValue( $summData->min5, $temperatureUnit, $summData->ch5_enable));
            $sData[$i]['avg5'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateThreeDecimalValue( $summData->avg5, $temperatureUnit, $summData->ch5_enable));
            $sData[$i]['max5'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateThreeDecimalValue( $summData->max5, $temperatureUnit, $summData->ch5_enable));
            $sData[$i]['min6'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateThreeDecimalValue( $summData->min6, $temperatureUnit, $summData->ch6_enable));
            $sData[$i]['avg6'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateThreeDecimalValue( $summData->avg6, $temperatureUnit, $summData->ch6_enable));
            $sData[$i]['max6'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::readingDataValidateThreeDecimalValue( $summData->max6, $temperatureUnit, $summData->ch6_enable));
            $i++;
        }
        $projectName = $projectData->project_name;
        $createdBy = 'System generated';

        $startDate = Carbon::createFromFormat('d/m/Y H:i:s', $params['startDate'])->format('Y-m-d H:i:s');
        $endDate = Carbon::createFromFormat('d/m/Y H:i:s', $params['endDate'])->format('Y-m-d H:i:s');
        $stDt = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZoneFormat($startDate, $projectData->project_time_zone, 'Y-m-d H:i:s');
        $endDt = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZoneFormat($endDate, $projectData->project_time_zone, 'Y-m-d H:i:s');

        $dateSpan = $stDt . ' to ' . $endDt;
        $date1_ts = strtotime($startDate);
        $date2_ts = strtotime($endDate);
        $diff = $date2_ts - $date1_ts;
        $reportPeriod = round($diff / 86400);

        $titleData = array(
            1 => ['titleText' => 'Project Name:', 'titleValue' => $projectName, 'width' => 100, 'align' => 'L', 'fillColor' => true],
            2 => ['titleText' => 'Report Period:', 'titleValue' => $reportPeriod . ' Day(s)', 'width' => 100, 'align' => 'L', 'fillColor' => true],
            3 => ['titleText' => 'Date Span:', 'titleValue' => $dateSpan, 'width' => 100, 'align' => 'L', 'fillColor' => true],
            4 => ['titleText' => 'Temperature Units:', 'titleValue' => ucfirst($temperatureUnit), 'width' => 100, 'align' => 'L', 'fillColor' => true],
            5 => ['titleText' => 'Created By:', 'titleValue' => $createdBy, 'width' => 100, 'align' => 'L', 'fillColor' => true],
            6 => ['titleText' => 'Time Zone:', 'titleValue' => $projectData->project_time_zone, 'width' => 100, 'align' => 'L', 'fillColor' => true],
            7 => ['titleText' => 'File Created at:', 'titleValue' => \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZoneFormat(Carbon::now()->format('Y-m-d H:i:s'), $projectData->project_time_zone, 'Y-m-d H:i:s T'), 'width' => 100, 'align' => 'L', 'fillColor' => true],
        );
        $pdf = $this->summaryPdfReport($pdf, $sData, $titleData);
        $pdf = $this->transmitterInfoPdfReport($pdf, $transInfo, $dateSpan);
        $projectDeviceAlarm = new \App\Models\ProjectDeviceAlarm;
        $filter = [];
        $filter['eventTimeSetStart'] = Carbon::createFromFormat('d/m/Y H:i:s', $params['startDate'])->format('Y-m-d');
        $filter['eventTimeSetEnd'] = Carbon::createFromFormat('d/m/Y H:i:s', $params['endDate'])->format('Y-m-d');
        $filter['schema'] = $schema;
        $activeAlarmList = $alarmDataService->activeSensorAlarmV2($projectId, $filter, $projectDeviceAlarm);
        $activeAlarmData = [];
        $data = [];
        $index = 1;
        if (!empty($activeAlarmList)) {
            foreach ($activeAlarmList as $activeAlarm) {
                $alarmSource = \App\Libraries\Helpers\SignatrolHtmlHelper::getAlarmSourceByCode($activeAlarm->alarm_source);
                $temp = [];
                $temp['index'] = $index;
                $temp['location'] = ($activeAlarm->device_location_name!=null)?$activeAlarm->device_location_name : \App\Libraries\Helpers\SignatrolHtmlHelper::macAddressDecToHex($activeAlarm->device_mac_address);
                $temp['alarm_state'] = \App\Libraries\Helpers\SignatrolHtmlHelper::getAlarmStateByCode($activeAlarm->alarm_state_code);
                $temp['channel'] = $alarmSource;
                $temp['event_time'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($activeAlarm->event_time_set, $projectData->project_time_zone);
                $temp['alarm_raised'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($activeAlarm->alarm_raised, $projectData->project_time_zone);
                $temp['setpoint'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::findAndFormatSetPoint($activeAlarm->setpoint, $alarmSource, $activeAlarm->temperature_units));
                $temp['channel_value'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::findAndFormatSetPoint($activeAlarm->channel_value, $alarmSource, $activeAlarm->temperature_units));
                $temp['ack_user'] =  ucwords(\App\Libraries\Helpers\SignatrolHtmlHelper::decrypt($activeAlarm->first_name) . ' ' . \App\Libraries\Helpers\SignatrolHtmlHelper::decrypt($activeAlarm->last_name)) ;
                $temp['comment'] = $activeAlarm->comment." ";
                $data[] = $temp;
                ++$index;
            }
        }
        $pdf = $this->activeSensorAlarmPdfReport($pdf, $data, $dateSpan);

        $filterForAwaits = [
            'actualStartDate' => $startDate,
            'actualEndDate' => $endDate,
            'schema' => $schema
        ];
        /*Add awaiting sensor alarm data starts*/
        $awaitingAlarmList = $alarmDataService->awaitingSensorAlarmV2($projectId, $filterForAwaits, $projectDeviceAlarm,true);
        $awaitingAlarmData = [];
        $data = [];
        $index = 1;
        if (!empty($awaitingAlarmList)) {
            foreach ($awaitingAlarmList as $historyAlarm) {
                $alarmSource = \App\Libraries\Helpers\SignatrolHtmlHelper::getAlarmSourceByCode($historyAlarm->alarm_source);
                $temp = [];
                $temp['index'] = $index;
                $temp['location'] = ($historyAlarm->device_location_name!=null)?$historyAlarm->device_location_name : \App\Libraries\Helpers\SignatrolHtmlHelper::macAddressDecToHex($historyAlarm->device_mac_address);
                $temp['alarm_state'] = \App\Libraries\Helpers\SignatrolHtmlHelper::getAlarmStateByCode($historyAlarm->alarm_state_code);
                $temp['channel'] = $alarmSource;
                $temp['event_time'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($historyAlarm->event_time_set, $projectData->project_time_zone);
                $temp['alarm_raised'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($historyAlarm->alarm_raised, $projectData->project_time_zone);
                $temp['event_time_clear'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($historyAlarm->event_time_clear, $projectData->project_time_zone);
                $temp['setpoint'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::findAndFormatSetPoint($historyAlarm->setpoint, $alarmSource, $historyAlarm->temperature_units));
                $temp['channel_value'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::findAndFormatSetPoint($historyAlarm->channel_value, $alarmSource, $historyAlarm->temperature_units));
                $data[] = $temp;
                ++$index;
            }
        }
        $pdf = $this->awaitingSensorAlarmPdfReport($pdf, $data, $dateSpan);
        /*Add awaiting sensor alarm data ends*/

        /*Add archived sensor alarm data starts*/
        $filterForHistorical = [
            'actualStartDate' => $startDate,
            'actualEndDate' => $endDate,
            'schema' => $schema
        ];
        $historicalAlarmList = $alarmDataService->historicalSensorAlarmV2($projectId, $filterForHistorical, $projectDeviceAlarm,true);
        $historicalAlarmData = [];
        $data = [];
        $index = 1;
        if (!empty($historicalAlarmList)) {
            foreach ($historicalAlarmList as $historyAlarm) {
                $alarmSource = \App\Libraries\Helpers\SignatrolHtmlHelper::getAlarmSourceByCode($historyAlarm->alarm_source);
                $temp = [];
                $temp['index'] = $index;
                $temp['location'] = ($historyAlarm->device_location_name!=null)?$historyAlarm->device_location_name : \App\Libraries\Helpers\SignatrolHtmlHelper::macAddressDecToHex($historyAlarm->device_mac_address);
                $temp['alarm_state'] = \App\Libraries\Helpers\SignatrolHtmlHelper::getAlarmStateByCode($historyAlarm->alarm_state_code);
                $temp['channel'] = $alarmSource;
                $temp['event_time'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($historyAlarm->event_time_set, $projectData->project_time_zone);
                $temp['alarm_raised'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($historyAlarm->alarm_raised, $projectData->project_time_zone);
                $temp['event_time_clear'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($historyAlarm->event_time_clear, $projectData->project_time_zone);
                $temp['setpoint'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::findAndFormatSetPoint($historyAlarm->setpoint, $alarmSource, $historyAlarm->temperature_units));
                $temp['channel_value'] = \App\Libraries\Helpers\SignatrolHtmlHelper::UTFencode(\App\Libraries\Helpers\SignatrolHtmlHelper::findAndFormatSetPoint($historyAlarm->channel_value, $alarmSource, $historyAlarm->temperature_units));
                $temp['ack_user'] =  ucwords(SignatrolHtmlHelper::decrypt($historyAlarm->first_name) . ' ' . SignatrolHtmlHelper::decrypt($historyAlarm->last_name)) ;
                $temp['comment'] = $historyAlarm->comment." ";
                $data[] = $temp;
                ++$index;
            }
        }
        $pdf = $this->historySensorAlarmPdfReport($pdf, $data, $dateSpan);
        /*Add archived sensor alarm data ends*/

        /*Add archived system alarm data starts*/
        $filterForActive = [
            'schema' => $schema
        ];
        $systemAlarmList = $alarmDataService->activeSystemAlarmV2($projectId, $filterForActive, $projectDeviceSystemAlarm);
        $historicalAlarmData = [];
        $data = [];
        $index = 1;
        if (!empty($systemAlarmList)) {
            foreach ($systemAlarmList as $systemAlarm) {
                $alarmSource = \App\Libraries\Helpers\SignatrolHtmlHelper::getAlarmSourceByCode($systemAlarm->system_alarm_source);
                $temp = [];
                $temp['index'] = $index;
                $temp['location'] = ($systemAlarm->device_location_name!=null)?$systemAlarm->device_location_name : $systemAlarm->device_mac_address;
                $temp['alarm_state'] = $alarmSource;
                $temp['event_time'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($systemAlarm->event_time_set, $projectData->project_time_zone);
                $temp['comment'] = $systemAlarm->comment." ";
                $data[] = $temp;
                ++$index;
            }
        }

        $pdf = $this->ActiveSystemAlarmPdfReport($pdf, $data, $dateSpan);
        /*Add archived system alarm data ends*/

        /*Add archived system alarm data starts*/
        $systemAlarmHistoryList = $alarmDataService->historicalSystemAlarmV2($projectId, $filter, $projectDeviceSystemAlarm);
        $historicalAlarmData = [];
        $data = [];
        $index = 1;
        if (!empty($systemAlarmHistoryList)) {
            foreach ($systemAlarmHistoryList as $systemAlarm) {
                $alarmSource = \App\Libraries\Helpers\SignatrolHtmlHelper::getAlarmSourceByCode($systemAlarm->system_alarm_source);
                $temp = [];
                $temp['index'] = $index;
                $temp['location'] = ($systemAlarm->device_location_name!=null)?$systemAlarm->device_location_name : $systemAlarm->device_mac_address;
                $temp['alarm_state'] = $alarmSource;
                $temp['event_time'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($systemAlarm->event_time_set, $projectData->project_time_zone);
                $temp['event_time_clear'] = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToTimeZone($systemAlarm->event_time_clear, $projectData->project_time_zone);
                $temp['comment'] = $systemAlarm->comment." ";
                $data[] = $temp;
                ++$index;
            }
        }
        $pdf = $this->ActiveSystemAlarmHistoryPdfReport($pdf, $data, $dateSpan);
        $pdf->reportEndPaging();
        if (!\File::exists(AUTO_REPORT_DIR)) {
            \File::makeDirectory(AUTO_REPORT_DIR, 0777, true);
        }
        $filename = 'project-' . $projectId . '-' . $reportType . '-' . 'pdf-report.pdf';
        $filePath = AUTO_REPORT_DIR . $filename;
        $pdf->Output($filePath, 'F');
        return $filePath;
    }

    /**
     *
     * @param Request $request
     * @param \App\Models\ProjectDevice $projectDevice
     * @param \App\Models\ReadingDataPacket $readingDataPacket
     * @return type
     */
    public function summaryPdfReport($pdf, $data, $titleData) {

        $header = array(
            1 => ['headerText' => 'No.', 'width' => 8, 'align' => 'C', 'fillColor' => true, 'color' => [] ],
            2 => ['headerText' => 'Location', 'width' => 27, 'align' => 'C', 'fillColor' => true, 'color' => [] ],
            3 => ['headerText' => 'min', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [133,190,219] ],
            4 => ['headerText' => 'avg', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [171,240,170] ],
            5 => ['headerText' => 'max', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [240,150,125] ],

            6 => ['headerText' => 'min', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [133,190,219] ] ,
            7 => ['headerText' => 'avg', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [171,240,170] ] ,
            8 => ['headerText' => 'max', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [240,150,125] ],

            9 => ['headerText' => 'min', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' =>  [133,190,219] ] ,
            10 => ['headerText' => 'avg', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [171,240,170] ] ,
            11 => ['headerText' => 'max', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [240,150,125] ],

            12 => ['headerText' => 'min', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [133,190,219] ] ,
            13 => ['headerText' => 'avg', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [171,240,170] ] ,
            14 => ['headerText' => 'max', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [240,150,125] ],

            15 => ['headerText' => 'min', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [133,190,219] ] ,
            16 => ['headerText' => 'avg', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [171,240,170] ] ,
            17 => ['headerText' => 'max', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [240,150,125] ],

            18 => ['headerText' => 'min', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [133,190,219] ] ,
            19 => ['headerText' => 'avg', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [171,240,170] ] ,
            20 => ['headerText' => 'max', 'width' => 14, 'align' => 'C', 'fillColor' => true, 'color' => [240,150,125] ],
        );
        $dataList = $data;

        $pdf->AliasNbPages();
        $pdf->SetFont('Arial', '', 8);
        $pdf->AddPage('L');

        //$pdf->AddPage('A4');
        //$pdf->AddPage('L');

        $pdf->AcceptPageBreak();
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->Header('Automatic PDF Report');
        $headerStyle = ['family' => '', 'style' => 'B', 'size' => 8];
        $textStyle = ['family' => '', 'style' => '', 'size' => 6];
        //$pdf->Ln(10);
        //$tableHeader

        $pdf->customTablePaging($header, $dataList, $headerStyle, $textStyle, $titleData, true, [], 1, 1);
        //$pdf->Ln(20);
        return $pdf;
        //return $pdf->Output('D', 'pdf-report-'. time() . '.pdf');
    }

    /**
     *
     * @param Request $request
     * @param \App\Models\ProjectDevice $projectDevice
     * @param \App\Models\ReadingDataPacket $readingDataPacket
     * @return type
     */
    public function transmitterInfoPdfReport($pdf, $data, $dateSpan) {

        $header = array(
            /*1 => ['headerText' => 'No.', 'width' => 30, 'align' => 'C', 'fillColor' => true],*/
            1 => ['headerText' => 'Location', 'width' => 80, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => 'Last Received Message @', 'width' => 75, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Calibration Date', 'width' => 75, 'align' => 'C', 'fillColor' => true],
            4 => ['headerText' => 'Battery Capacity', 'width' => 40, 'align' => 'C', 'fillColor' => true]
        );
        $widthSum = 0;
        foreach ($header as $row) {
            $widthSum = $widthSum + $row['width'];
        }
        $topRow = array(
            1 => ['headerText' => 'Transmitter Information', 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => $dateSpan, 'width' => $widthSum, 'align' => 'C', 'fillColor' => true]
        );

        $dataList = $data;

        $pdf->AliasNbPages();
        $pdf->SetFont('Arial', '', 8);
        $pdf->AddPage('L');

        //$pdf->AddPage('A4');
        //$pdf->AddPage('L');

        $pdf->AcceptPageBreak();
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->Header('Automatic PDF Report');
        $headerStyle = ['family' => '', 'style' => 'B', 'size' => 8];
        $textStyle = ['family' => '', 'style' => '', 'size' => 7];
        //$pdf->Ln(10);
        //$pdf->SetX(1);
        //$pdf->SetY(10);
        $pdf->customTablePaging($header, $dataList, $headerStyle, $textStyle, [], false, $topRow);
        $pdf->Ln(20);
        return $pdf;
    }

    /**
     *
     * @param Request $request
     * @param \App\Models\ProjectDevice $projectDevice
     * @param \App\Models\ReadingDataPacket $readingDataPacket
     * @return type
     */
    public function activeSensorAlarmPdfReport($pdf, $data, $dateSpan) {

        $header = array(
            1 => ['headerText' => 'No.', 'width' => 15, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => 'Location', 'width' => 30, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Type', 'width' => 20, 'align' => 'C', 'fillColor' => true],
            4 => ['headerText' => 'Channel', 'width' => 25, 'align' => 'C', 'fillColor' => true],
            5 => ['headerText' => 'Event Time', 'width' => 35, 'align' => 'C', 'fillColor' => true],
            6 => ['headerText' => 'Alarm Raised', 'width' => 35, 'align' => 'C', 'fillColor' => true],
//            7 => ['headerText' => 'Clear', 'width' => 25, 'align' => 'C', 'fillColor' => true],
            7 => ['headerText' => 'Setpoint', 'width' => 22, 'align' => 'C', 'fillColor' => true],
            8 => ['headerText' => 'Value', 'width' => 20, 'align' => 'C', 'fillColor' => true],
            9 =>['headerText' => 'Ack User', 'width' =>25, 'align' => 'C', 'fillColor' => true],
            10 =>['headerText' => 'Ack Comment', 'width' =>52, 'align' => 'C', 'fillColor' => true]
        );
        $widthSum = 0;
        foreach ($header as $row) {
            $widthSum = $widthSum + $row['width'];
        }
        $topRow = array(
            1 => ['headerText' => 'Active Sensor Alarms', 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => $dateSpan, 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Alarm Count = ' . count($data), 'width' => $widthSum, 'align' => 'C', 'fillColor' => true]
        );

        $dataList = $data;

        $pdf->AliasNbPages();
        $pdf->SetFont('Arial', '', 8);
        $pdf->AddPage('L');

        //$pdf->AddPage('A4');
        //$pdf->AddPage('L');

        $pdf->AcceptPageBreak();
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->Header('Active Sensor Alarms');
        $headerStyle = ['family' => '', 'style' => 'B', 'size' => 8];
        $textStyle = ['family' => '', 'style' => '', 'size' => 7];
        //$pdf->Ln(10);
        //$pdf->SetX(1);
        //$pdf->SetY(10);
        $pdf->customTablePaging($header, $dataList, $headerStyle, $textStyle, [], false, $topRow, 1);
        $pdf->Ln(20);
        return $pdf;
        //return $pdf->Output('D', 'pdf-report-'. time() . '.pdf');
    }

    /**
     *
     * @param Request $request
     * @param \App\Models\ProjectDevice $projectDevice
     * @param \App\Models\ReadingDataPacket $readingDataPacket
     * @return type
     */
    public function awaitingSensorAlarmPdfReport($pdf, $data, $dateSpan) {

        $header = array(
            1 => ['headerText' => 'No.', 'width' => 15, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => 'Location', 'width' => 40, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Type', 'width' => 20, 'align' => 'C', 'fillColor' => true],
            4 => ['headerText' => 'Channel', 'width' => 30, 'align' => 'C', 'fillColor' => true],
            5 => ['headerText' => 'Event Time', 'width' => 40, 'align' => 'C', 'fillColor' => true],
            6 => ['headerText' => 'Alarm Raised', 'width' => 40, 'align' => 'C', 'fillColor' => true],
            7 => ['headerText' => 'Clear', 'width' => 35, 'align' => 'C', 'fillColor' => true],
            8 => ['headerText' => 'Setpoint', 'width' => 25, 'align' => 'C', 'fillColor' => true],
            9 => ['headerText' => 'Value', 'width' => 25, 'align' => 'C', 'fillColor' => true]
        );
        $widthSum = 0;
        foreach ($header as $row) {
            $widthSum = $widthSum + $row['width'];
        }
        $topRow = array(
            1 => ['headerText' => 'Cleared Sensor Alarms, Awaiting Acknowledgement', 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => $dateSpan, 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Alarm Count = ' . count($data), 'width' => $widthSum, 'align' => 'C', 'fillColor' => true]
        );

        $dataList = $data;

        $pdf->AliasNbPages();
        $pdf->SetFont('Arial', '', 8);
        $pdf->AddPage('L');

        //$pdf->AddPage('A4');
        //$pdf->AddPage('L');

        $pdf->AcceptPageBreak();
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->Header('Cleared Sensor Alarms, Awaiting Acknowledgement');
        $headerStyle = ['family' => '', 'style' => 'B', 'size' => 8];
        $textStyle = ['family' => '', 'style' => '', 'size' => 7];
        //$pdf->Ln(10);
        //$pdf->SetX(1);
        //$pdf->SetY(10);
        $pdf->customTablePaging($header, $dataList, $headerStyle, $textStyle, [], false, $topRow);
        $pdf->Ln(20);
        return $pdf;
        //return $pdf->Output('D', 'pdf-report-'. time() . '.pdf');
    }

    /**
     *
     * @param Request $request
     * @param \App\Models\ProjectDevice $projectDevice
     * @param \App\Models\ReadingDataPacket $readingDataPacket
     * @return type
     */
    public function historySensorAlarmPdfReport($pdf, $data, $dateSpan) {

        $header = array(
            1 => ['headerText' => 'No.', 'width' => 13, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => 'Location', 'width' => 35, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Alarm Type', 'width' => 22, 'align' => 'C', 'fillColor' => true],
            4 => ['headerText' => 'Channel', 'width' => 22, 'align' => 'C', 'fillColor' => true],
            5 => ['headerText' => 'Event Time', 'width' => 29, 'align' => 'C', 'fillColor' => true],
            6 => ['headerText' => 'Alarm Raised', 'width' => 29, 'align' => 'C', 'fillColor' => true],
            7 => ['headerText' => 'Clear', 'width' => 29, 'align' => 'C', 'fillColor' => true],
            8 => ['headerText' => 'Setpoint', 'width' => 18, 'align' => 'C', 'fillColor' => true],
            9 => ['headerText' => 'Value', 'width' => 18, 'align' => 'C', 'fillColor' => true],
            10 =>['headerText' => 'Ack User', 'width' =>23, 'align' => 'C', 'fillColor' => true],
            11 =>['headerText' => 'Ack Comment', 'width' =>32, 'align' => 'C', 'fillColor' => true]
        );
        $widthSum = 0;
        foreach ($header as $row) {
            $widthSum = $widthSum + $row['width'];
        }
        $topRow = array(
            1 => ['headerText' => 'Sensor Alarm History, Cleared and Acknowledged', 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => $dateSpan, 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Alarm Count = ' . count($data), 'width' => $widthSum, 'align' => 'C', 'fillColor' => true]
        );

        $dataList = $data;

        $pdf->AliasNbPages();
        $pdf->SetFont('Arial', '', 8);
        $pdf->AddPage('L');

        //$pdf->AddPage('A4');
        //$pdf->AddPage('L');

        $pdf->AcceptPageBreak();
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->Header('Sensor Alarm History, Cleared and Acknowledged');
        $headerStyle = ['family' => '', 'style' => 'B', 'size' => 8];
        $textStyle = ['family' => '', 'style' => '', 'size' => 7];
        //$pdf->Ln(10);
        //$pdf->SetX(1);
        //$pdf->SetY(10);
        $pdf->customTablePaging($header, $dataList, $headerStyle, $textStyle, [], false, $topRow, 1);
        $pdf->Ln(20);
        return $pdf;
        //return $pdf->Output('D', 'pdf-report-'. time() . '.pdf');
    }

    /**
     * Converts the report time to correct utc report time per the project timezone.
     * @date 23 April 2020
     */
    public function convertAndUpdateDate($companySchemas){
        foreach ($companySchemas as $key => $companySchema) {
            $schema = $companySchema['schema'];
            $projects = $schema.'.'.'projects';
            $application_options = $schema.'.'.'application_options';
            $application_Options = DB::table($application_options)->select('application_options.id as aoid', '*')
                ->where('application_options.utc_report_time', '<>', '00:00:00')
                ->join($projects, 'projects.id', '=', 'application_options.project_id')
                ->get();
            if(count($application_Options)){
                foreach($application_Options as $application_Option){
                    $date = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToUTCTime(Carbon::now()->format('Y-m-d '.  $application_Option->report_time) , $application_Option->project_time_zone);
                    DB::table($application_options)->where('id', '=', $application_Option->aoid)->where('utc_report_time', '<>', $date)->update([
                            'utc_report_time' => $date
                    ]);
                }
            }
        }
    }

    public function convertAndUpdateDateOld(){
        $application_Options = ApplicationOption::select('application_options.id as aoid', '*')
            ->where('application_options.utc_report_time', '<>', '00:00:00')
            ->join('projects', 'projects.id', '=', 'application_options.project_id')
            ->get();
        if(count($application_Options)){
            foreach($application_Options as $application_Option){
                $date = \App\Libraries\Helpers\SignatrolHtmlHelper::convertToUTCTime(Carbon::now()->format('Y-m-d '.  $application_Option->report_time) , $application_Option->project_time_zone);

                ApplicationOption::where('id', '=', $application_Option->aoid)->where('utc_report_time', '<>', $date)->update([
                        'utc_report_time' => $date
                ]);
            }
        }
    }
    
    /**
     *
     * @param $pdf
     * @param $data
     * @param $dateSpan
     * @return type
     */
    public function ActiveSystemAlarmPdfReport($pdf, $data, $dateSpan) {

        $header = array(
            1 => ['headerText' => 'No.', 'width' => 13, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => 'Location', 'width' => 70, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Type', 'width' => 30, 'align' => 'C', 'fillColor' => true],
            4 => ['headerText' => 'Event Time(Set)', 'width' => 35, 'align' => 'C', 'fillColor' => true],
            5 =>['headerText' => 'General Comment', 'width' =>122, 'align' => 'C', 'fillColor' => true]
        );
        $widthSum = 0; //270
        foreach ($header as $row) {
            $widthSum = $widthSum + $row['width'];
        }
        $topRow = array(
            1 => ['headerText' => 'Active System Alarm' , 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => $dateSpan, 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Alarm Count = '.count($data), 'width' => $widthSum, 'align' => 'C', 'fillColor' => true]
        );

        $dataList = $data;

        $pdf->AliasNbPages();
        $pdf->SetFont('Arial', '', 8);
        $pdf->AddPage('L');

        //$pdf->AddPage('A4');
        //$pdf->AddPage('L');

        $pdf->AcceptPageBreak();
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->Header('Active System Alarm');
        $headerStyle = ['family' => '', 'style' => 'B', 'size' => 8];
        $textStyle = ['family' => '', 'style' => '', 'size' => 7];
        //$pdf->Ln(10);
        //$pdf->SetX(1);
        //$pdf->SetY(10);
        $pdf->customTablePaging($header, $dataList, $headerStyle, $textStyle, [], false, $topRow, 1);
        $pdf->Ln(20);
        return $pdf;
        //return $pdf->Output('D', 'pdf-report-'. time() . '.pdf');
    }


    /**
     *
     * @param $pdf
     * @param $data
     * @param $dateSpan
     * @return type
     */
    public function ActiveSystemAlarmHistoryPdfReport($pdf, $data, $dateSpan) {

        $header = array(
            1 => ['headerText' => 'No.', 'width' => 13, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => 'Location', 'width' => 70, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Type', 'width' => 30, 'align' => 'C', 'fillColor' => true],
            4 => ['headerText' => 'Event Time(Set)', 'width' => 35, 'align' => 'C', 'fillColor' => true],
            5 => ['headerText' => 'Event Time(Clear)', 'width' => 35, 'align' => 'C', 'fillColor' => true],
            6 =>['headerText' => 'General Comment', 'width' =>87, 'align' => 'C', 'fillColor' => true]
        );
        $widthSum = 0; //270
        foreach ($header as $row) {
            $widthSum = $widthSum + $row['width'];
        }
        $topRow = array(
            1 => ['headerText' => 'Active System Alarm History' , 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            2 => ['headerText' => $dateSpan, 'width' => $widthSum, 'align' => 'C', 'fillColor' => true],
            3 => ['headerText' => 'Alarm Count = '.count($data), 'width' => $widthSum, 'align' => 'C', 'fillColor' => true]
        );

        $dataList = $data;

        $pdf->AliasNbPages();
        $pdf->SetFont('Arial', '', 8);
        $pdf->AddPage('L');

        //$pdf->AddPage('A4');
        //$pdf->AddPage('L');

        $pdf->AcceptPageBreak();
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->Header('Active System Alarm History');
        $headerStyle = ['family' => '', 'style' => 'B', 'size' => 8];
        $textStyle = ['family' => '', 'style' => '', 'size' => 7];
        //$pdf->Ln(10);
        //$pdf->SetX(1);
        //$pdf->SetY(10);
        $pdf->customTablePaging($header, $dataList, $headerStyle, $textStyle, [], false, $topRow, 1);
        $pdf->Ln(20);
        return $pdf;
        //return $pdf->Output('D', 'pdf-report-'. time() . '.pdf');
    }

}
