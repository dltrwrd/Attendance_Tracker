// Configuration
var CONFIG = {
  phpEndpoint:
    "https://lightpink-cormorant-243207.hostingersite.com/api/mysql-to-sheets.php",
  refreshInterval: 10, // seconds
  timeout: 10, // seconds for URLFetch
};

// Global variables
var refreshTriggerId = null;
var lastExecutionTime = null;

function fetchDataFromPHP() {
  var startTime = new Date();
  console.log("Starting fetch at: " + startTime);

  // Check if previous execution is still running
  if (
    lastExecutionTime &&
    new Date() - lastExecutionTime < CONFIG.refreshInterval * 1000 - 1000
  ) {
    console.log("Skipping - previous execution still running");
    return;
  }
  lastExecutionTime = startTime;

  var spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  var absenteeismSheet = spreadsheet.getSheetByName("AbsenteeismData");
  var tardinessSheet = spreadsheet.getSheetByName("TardinessData");
  var vtoTrackerSheet = spreadsheet.getSheetByName("vtoData"); // New sheet for VTO tracker
  var headsetTrackerSheet = spreadsheet.getSheetByName("HeadsetData"); // New sheet for VTO tracker
  var irTrackerSheet = spreadsheet.getSheetByName("irData"); // New sheet for Incident Report tracker
  var ticketingSheet = spreadsheet.getSheetByName("TicketingData"); // New sheet for TicketingData

  try {
    // Process sheets sequentially (more reliable than parallel in Apps Script)
    if (absenteeismSheet) {
      processSheet("absenteeism", absenteeismSheet);
      // ABSENTEEISM is a QUERY() formula off AbsenteeismData, so flushing lets the "fire" value
      // spill through immediately, then we check right away instead of waiting for
      // checkForFireTriggersAbsent's own separate 1-5 min trigger to eventually catch it.
      SpreadsheetApp.flush();
      checkForFireTriggersAbsent();
    }

    if (tardinessSheet) {
      processSheet("tardiness", tardinessSheet);
    }

    // Add processing for VTO tracker
    if (vtoTrackerSheet) {
      processSheet("vto_tracker", vtoTrackerSheet);
      // Same reasoning as absenteeism above -- VTO is a QUERY() formula off vtoData.
      SpreadsheetApp.flush();
      checkForFireTriggersVTO();
    }

    // Add processing for Headset tracker
    if (headsetTrackerSheet) {
      processSheet("headset_tracker", headsetTrackerSheet);
    }

    // Add processing for Incident Report tracker
    if (irTrackerSheet) {
      processSheet("incident_report", irTrackerSheet);
    }

    // Add processing for Ticketing tracker
    if (ticketingSheet) {
      processSheet("ticket", ticketingSheet);
    }

    console.log("Total execution time: " + (new Date() - startTime) + "ms");
    return true;
  } catch (e) {
    console.error("Error in fetchDataFromPHP: " + e.toString());
    return false;
  }
}

function processSheet(type, sheet) {
  console.log("Processing " + type + " data");

  var url = CONFIG.phpEndpoint + "?type=" + type;
  var response = UrlFetchApp.fetch(url, {
    muteHttpExceptions: true,
    timeout: CONFIG.timeout * 1000,
  });

  if (response.getResponseCode() !== 200) {
    throw new Error("HTTP " + response.getResponseCode() + " fetching " + type);
  }

  var jsonData = JSON.parse(response.getContentText());

  if (!jsonData.success || !jsonData.data) {
    throw new Error("Invalid data format for " + type);
  }

  // Prepare values
  var values = jsonData.data.map(function (row) {
    if (type === "absenteeism") {
      return [
        row.month,
        row.employee_id,
        row.full_name,
        row.department,
        row.supervisor,
        row.operation_manager,
        row.date_of_absent,
        row.follow_call_in_procedure,
        row.sanction,
        row.reason,
        row.coverage_employee_id_1,
        row.coverage_1,
        row.coverage_type_1,
        row.coverage_details_1,
        row.coverage_employee_id_2,
        row.coverage_2,
        row.coverage_type_2,
        row.coverage_details_2,
        row.coverage_employee_id_3,
        row.coverage_3,
        row.coverage_type_3,
        row.coverage_details_3,
        row.coverage_employee_id_4,
        row.coverage_4,
        row.coverage_type_4,
        row.coverage_details_4,
        row.shift,
        row.ir_form,
        row.timestamp,
        row.sub_name,
        row.email_sent_at,
        row.fire_trigger,
        row.trigger_date,
      ];
    } else if (type === "tardiness") {
      return [
        row.month,
        row.employee_id,
        row.full_name,
        row.department,
        row.supervisor,
        row.operation_manager,
        row.date_of_incident,
        row.types,
        row.minutes_late,
        row.shift,
        row.time_in,
        row.ir_form,
        row.timestamp,
        row.sub_name,
        row.email_sent_at,
        row.fire_trigger,
        row.trigger_date,
        row.coverage_employee_id_1,
        row.coverage_1,
        row.coverage_type_1,
        row.coverage_details_1,
      ];
    } else if (type === "vto_tracker") {
      return [
        row.shift_date,
        row.month,
        row.employee_id,
        row.full_name,
        row.department,
        row.supervisor,
        row.operation_manager,
        row.shift,
        row.coverage,
        row.coverage_type,
        row.time_in,
        row.time_out,
        row.mins_of_work,
        row.vto_mins,
        row.vto_type,
        row.approved_by,
        row.sub_name,
        row.fire_trigger,
        row.trigger_date,
      ];
    } else if (type === "headset_tracker") {
      return [
        row.week_beginning,
        row.date_issued,
        row.employee_id,
        row.full_name,
        row.department,
        row.operation_manager,
        row.brand_model_no,
        row.c_no,
        row.yjack_serial_no,
        row.w_xtra_foam,
        row._condition,
        row.release_by,
        row.release_time,
        row.received_by,
        row.return_time,
        row.return_date,
        row.equipment_status,
        row.remarks,
      ];
    } else if (type === "incident_report") {
      return [
        row.email_address,
        row.employee_id,
        row.full_name,
        row.department,
        row.operation_manager,
        row.infraction,
        row.reported_by,
        row.position,
        row.date_of_incident,
        row.shift,
        row.incident_details,
        row.evidence,
        row.created_at,
      ];
    } else if (type === "ticket") {
      return [
        row.Timestamp,
        row.Email_Address,
        row.Site,
        row.Affected_employee,
        row.EID,
        row.Issues_Concerning,
        row.Station_Number,
        row.TIME_RECEIVED,
        row.TIME_RESOLVED,
        row.SLT_on_DUTY,
        row.Week_Beginning,
        row.LOB,
        row.OM,
        row.Employee_name,
        row.Work_Number,
        row.Status,
        row.Urgency,
        row.Issue_Details,
        row.resolution,
      ];
    }
  });

  // FIXED: Update sheet without losing existing data during refresh
  if (values.length > 0) {
    // Clear only the range where data might be, not the entire sheet
    var lastRow = sheet.getLastRow();
    if (lastRow > 1) {
      sheet.getRange(2, 1, lastRow - 1, sheet.getLastColumn()); // add this .clearContent() to clear the deleted tracked
    }

    // Set new values
    sheet.getRange(2, 1, values.length, values[0].length).setValues(values);
  } else {
    // If no data, clear only existing data rows (not entire sheet)
    var lastRow = sheet.getLastRow();
    if (lastRow > 1) {
      sheet.getRange(2, 1, lastRow - 1, sheet.getLastColumn()).clearContent();
    }
  }

  console.log("Updated " + type + " with " + values.length + " records");
}

function startAutoRefresh() {
  stopAutoRefresh(); // Clear any existing triggers

  // Create a time-based trigger
  refreshTriggerId = ScriptApp.newTrigger("fetchDataFromPHP")
    .timeBased()
    .everyMinutes(1) // Minimum interval is 1 minute
    .create()
    .getUniqueId();

  // Run immediately
  fetchDataFromPHP();

  console.log("Started auto-refresh with trigger ID: " + refreshTriggerId);
  SpreadsheetApp.getActiveSpreadsheet().toast(
    "Auto-refresh started (every ~10 seconds)",
    "Status",
    5,
  );
}

function stopAutoRefresh() {
  if (refreshTriggerId) {
    // Find and delete the trigger
    var triggers = ScriptApp.getProjectTriggers();
    for (var i = 0; i < triggers.length; i++) {
      if (triggers[i].getUniqueId() === refreshTriggerId) {
        ScriptApp.deleteTrigger(triggers[i]);
        break;
      }
    }
    refreshTriggerId = null;
  }

  console.log("Stopped auto-refresh");
  SpreadsheetApp.getActiveSpreadsheet().toast(
    "Auto-refresh stopped",
    "Status",
    5,
  );
}

function onOpen() {
  var ui = SpreadsheetApp.getUi();
  ui.createMenu("⚡ Data Sync")
    .addItem("Start Auto-Refresh (~10s)", "startAutoRefresh")
    .addItem("Stop Auto-Refresh", "stopAutoRefresh")
    .addItem("Refresh Now", "fetchDataFromPHP")
    .addToUi();
}

function onInstall() {
  onOpen();
}
