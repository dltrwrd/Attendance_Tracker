function autoNoteLate(e) {
  var mainSheetName = "TARDINESS";
  var triggerColumn = 16; // Column P
  var triggerValue = "fire";

  var range = e.range;
  var sheet = range.getSheet();

  if (
    sheet.getName() === mainSheetName &&
    range.getColumn() === triggerColumn
  ) {
    var rowNumber = range.getRow();
    var value = e.value;

    if (
      value &&
      typeof value === "string" &&
      value.toLowerCase() === triggerValue.toLowerCase()
    ) {
      addNoteTofile(rowNumber);
      range.clearContent();
    }
  }
}

function checkForFireTriggers() {
  try {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var sheet = ss.getSheetByName("TARDINESS");
    var triggerColumn = 16; // Column P

    if (!sheet) return;

    var lastRow = sheet.getLastRow();
    if (lastRow < 2) return;

    var dataRange = sheet.getRange(1, 1, lastRow, 20);
    var data = dataRange.getValues();

    for (var i = 1; i < data.length; i++) {
      var rowNumber = i + 1;
      var fireValue = data[i][15]; // Column P is index 15 (0-based)

      if (fireValue && fireValue.toString().toLowerCase() === "fire") {
        addNoteTofile(rowNumber);
        sheet.getRange(rowNumber, triggerColumn).clearContent();
      }
    }
  } catch (error) {
    Logger.log("Error in checkForFireTriggers: " + error.toString());
  }
}

function setupAutoFireTrigger() {
  // Remove any existing checkForFireTriggers triggers first
  var triggers = ScriptApp.getProjectTriggers();
  triggers.forEach(function (trigger) {
    if (trigger.getHandlerFunction() === "checkForFireTriggers") {
      ScriptApp.deleteTrigger(trigger);
    }
  });

  // Create new time-based trigger to run every 1 minute
  ScriptApp.newTrigger("checkForFireTriggers")
    .timeBased()
    .everyMinutes(1)
    .create();

  Browser.msgBox(
    "Success",
    "Auto-fire trigger setup complete! checkForFireTriggers will run every 1 minute.",
    Browser.Buttons.OK,
  );
}

function addNoteTofile(rowNumber) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var mainSheet = ss.getSheetByName("TARDINESS");

  var externalSpreadsheetId = "1XeyCSc3_cgMWXev2b-2XXZkmbzw6gdrigU-va1jx920";
  var externalSheetName = "AUG";
  var externalSs;

  try {
    externalSs = SpreadsheetApp.openById(externalSpreadsheetId);
  } catch (e) {
    Browser.msgBox(
      "Error",
      "Could not open the external spreadsheet. Please check the ID and your permissions. Error: " +
        e.message,
      Browser.Buttons.OK,
    );
    return;
  }

  var schedfileSheet = externalSs.getSheetByName(externalSheetName);
  if (!schedfileSheet) {
    Browser.msgBox(
      "Error",
      "The sheet named '" +
        externalSheetName +
        "' was not found in the external spreadsheet.",
      Browser.Buttons.OK,
    );
    return;
  }

  // --- GET VALUES ---
  var typeStr = mainSheet
    .getRange("H" + rowNumber)
    .getValue()
    .toString()
    .trim(); // Check if Late or Undertime
  var name = mainSheet.getRange("C" + rowNumber).getValue();
  var dateStrMain = mainSheet.getRange("G" + rowNumber).getDisplayValue();

  // Shift details
  var originalShift = mainSheet.getRange("J" + rowNumber).getValue();
  var punchTime = mainSheet.getRange("K" + rowNumber).getDisplayValue(); // Will be used for Time In OR Time Out
  var minslate = mainSheet.getRange("I" + rowNumber).getValue();
  var sltDuty = mainSheet.getRange("N" + rowNumber).getValue();

  // Undertime specific details (Assumed Columns - PLEASE UPDATE L, M, O if they are different in your sheet)
  var coverage = mainSheet.getRange("M" + rowNumber).getValue();
  var coverageType = mainSheet.getRange("N" + rowNumber).getValue();
  var coverageDetails = mainSheet.getRange("O" + rowNumber).getValue();

  if (!name || !dateStrMain) {
    Browser.msgBox(
      "Data Missing",
      "Please ensure 'Name' (Column C) and 'Date' (Column G) are filled in row " +
        rowNumber +
        ".",
      Browser.Buttons.OK,
    );
    return;
  }

  // --- MATCH DATES ---
  var mainDate = new Date(dateStrMain);
  var headerDates = schedfileSheet.getRange("1:1").getValues()[0];
  var dateColumn = -1;

  for (var i = 0; i < headerDates.length; i++) {
    var schedfileDateValue = headerDates[i];
    if (schedfileDateValue instanceof Date) {
      var formattedSchedfileDate = Utilities.formatDate(
        schedfileDateValue,
        externalSs.getSpreadsheetTimeZone(),
        "M/d/yyyy",
      );
      var formattedMainDate = Utilities.formatDate(
        mainDate,
        externalSs.getSpreadsheetTimeZone(),
        "M/d/yyyy",
      );

      if (formattedSchedfileDate === formattedMainDate) {
        dateColumn = i + 1;
        break;
      }
    }
  }

  if (dateColumn === -1) {
    Browser.msgBox(
      "Date Not Found",
      "The date '" +
        dateStrMain +
        "' was not found in the header row of '" +
        externalSheetName +
        "'.",
      Browser.Buttons.OK,
    );
    return;
  }

  // --- MATCH EMPLOYEE ID ---
  var empId = mainSheet.getRange("B" + rowNumber).getValue();
  var lastRow = schedfileSheet.getLastRow();
  var eidsInSchedfile = schedfileSheet.getRange("A1:A" + lastRow).getValues();
  var nameRow = -1;

  for (var j = 0; j < eidsInSchedfile.length; j++) {
    if (
      eidsInSchedfile[j][0] &&
      eidsInSchedfile[j][0].toString().trim() === empId.toString().trim()
    ) {
      nameRow = j + 1;
      break;
    }
  }

  if (nameRow === -1) {
    Browser.msgBox(
      "Employee ID Not Found",
      "The Employee ID '" +
        empId +
        "' was not found in Column A of '" +
        externalSheetName +
        "'.",
      Browser.Buttons.OK,
    );
    return;
  }

  // --- BUILD THE NOTE ---
  var targetCell = schedfileSheet.getRange(nameRow, dateColumn);
  var comment = "";

  if (typeStr.toLowerCase() === "undertime") {
    // UNDERTIME FORMAT
    comment =
      "UNDERTIME" +
      "\n" +
      "\nOriginal Shift: " +
      originalShift +
      "\nTIME IN : " +
      punchTime +
      "\nTIME OUT : " +
      "\nUNDERTIME MINUTES : " +
      minslate +
      " MINS" +
      "\nCoverage: " +
      "\nCoverage Type: " +
      "\nCoverage Details: " +
      "\n" +
      "\n" +
      sltDuty;
  } else {
    // LATE / TARDINESS FORMAT
    var title = typeStr !== "" ? typeStr.toUpperCase() : "TARDINESS";

    comment =
      title +
      "\n" +
      "\nOriginal Shift: " +
      originalShift +
      "\nTIME IN : " +
      punchTime +
      "\nMINUTES OF LATE: " +
      minslate +
      " MINS" +
      "\nREASON: NO ADVISE" +
      "\n" +
      "\n" +
      sltDuty;
  }

  targetCell.setNote(comment);
  targetCell.setFontColor("#FF0000");
}
