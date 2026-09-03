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
        try {
          addNoteTofile(rowNumber);
          sheet.getRange(rowNumber, triggerColumn).clearContent();
        } catch (rowError) {
          // Isolate this row's failure so one bad record doesn't block the rest of the batch.
          Logger.log(
            "Error firing late row " + rowNumber + ": " + rowError.toString(),
          );
        }
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
    "Auto-fire trigger setup complete! checkForFireTriggers will run every 2 minute.",
    Browser.Buttons.OK,
  );
}

function addNoteTofile(rowNumber) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var mainSheet = ss.getSheetByName("TARDINESS");

  var externalSpreadsheetId = "1XeyCSc3_cgMWXev2b-2XXZkmbzw6gdrigU-va1jx920";
  var externalSheetName = "SEPT";
  var externalSs;

  try {
    externalSs = SpreadsheetApp.openById(externalSpreadsheetId);
  } catch (e) {
    Logger.log(
      "addNoteTofile row " +
        rowNumber +
        ": could not open external spreadsheet. " +
        e.message,
    );
    return;
  }

  var schedfileSheet = externalSs.getSheetByName(externalSheetName);
  if (!schedfileSheet) {
    Logger.log(
      "addNoteTofile row " +
        rowNumber +
        ": sheet '" +
        externalSheetName +
        "' not found in external spreadsheet.",
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

  // Coverage columns verified against api/Code.gs tardiness write order:
  // R=coverage_employee_id_1, S=coverage_1, T=coverage_type_1, U=coverage_details_1
  var coverage = mainSheet.getRange("S" + rowNumber).getValue();
  var coverageType = mainSheet.getRange("T" + rowNumber).getValue();
  var coverageDetails = mainSheet.getRange("U" + rowNumber).getValue();

  if (!name || !dateStrMain) {
    Logger.log(
      "addNoteTofile row " +
        rowNumber +
        ": missing Name (Column C) or Date (Column G).",
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
    Logger.log(
      "addNoteTofile row " +
        rowNumber +
        ": date '" +
        dateStrMain +
        "' not found in header row of '" +
        externalSheetName +
        "'.",
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
    Logger.log(
      "addNoteTofile row " +
        rowNumber +
        ": Employee ID '" +
        empId +
        "' not found in Column A of '" +
        externalSheetName +
        "'.",
    );
    return;
  }

  var targetCell = schedfileSheet.getRange(nameRow, dateColumn);

  // --- BUILD THE NOTE ---
  var comment = "";
  var title = typeStr !== "" ? typeStr.toUpperCase() : "TARDINESS";

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
      coverage +
      "\nCoverage Type: " +
      coverageType +
      "\nCoverage Details: " +
      coverageDetails +
      "\n" +
      "\n" +
      sltDuty;
  } else {
    // LATE / TARDINESS FORMAT
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

  // Auto-note for the person who covered (Late/Undertime). Reuses isNoRealCoverer/
  // stackShiftLines/parseStartMinutes from autoNoteAbsent.gs (same GAS project, shared
  // global scope) to match the Absent/VTO pattern.
  if (
    coverage &&
    coverage.toString().trim() !== "" &&
    !isNoRealCoverer(coverage)
  ) {
    addLateCoverageNote(
      schedfileSheet,
      headerDates,
      mainDate,
      name,
      title,
      originalShift,
      coverage,
      coverageType,
      coverageDetails,
      sltDuty,
    );
  }

  // Overwrite this cell LAST, so an earlier-fired coverage note on this same cell (from
  // Absent/VTO) isn't wiped out.
  var existingNote = targetCell.getNote();
  if (existingNote && existingNote.trim() !== "") {
    if (existingNote.indexOf(comment.trim()) === -1) {
      targetCell.setNote(existingNote.trim() + "\n\n" + comment);
    }
  } else {
    targetCell.setNote(comment);
  }
  applyRedFontToOwnShiftLine(targetCell, originalShift);
}

// Colors ONLY the line matching the employee's own shift red -- if the cell is already
// stacked (e.g. they were covering someone else earlier), the other stacked line(s) keep
// their existing formatting instead of all turning red. Falls back to coloring the whole
// cell red if no matching line is found (single-line/blank cell, old simple behavior).
function applyRedFontToOwnShiftLine(targetCell, originalShift) {
  var richText = targetCell.getRichTextValue();
  var text = richText ? richText.getText() : targetCell.getValue().toString();
  if (!text) {
    targetCell.setFontColor("#FF0000");
    return;
  }

  var builder = richText
    ? richText.copy()
    : SpreadsheetApp.newRichTextValue().setText(text);
  var redStyle = SpreadsheetApp.newTextStyle()
    .setForegroundColor("#FF0000")
    .build();
  var ownShiftText = originalShift ? originalShift.toString().trim() : "";

  var lines = text.split("\n");
  var idx = 0;
  var matchFound = false;
  for (var k = 0; k < lines.length; k++) {
    var lineEnd = idx + lines[k].length;
    if (lines[k].trim() !== "" && lines[k].trim() === ownShiftText) {
      builder.setTextStyle(idx, lineEnd, redStyle);
      matchFound = true;
    }
    idx = lineEnd + 1; // +1 for the "\n" separator
  }

  if (!matchFound) {
    // Single line, or the stored text didn't match originalShift exactly -- color the whole
    // cell red, same as the old simple behavior.
    builder.setTextStyle(0, text.length, redStyle);
  }

  targetCell.setRichTextValue(builder.build());
}

// Writes coverage note + shift value on the coverer's cell in Schedfile. No color/format copy
// for late/undertime coverage -- the coverer keeps their own font color, note+value only.
function addLateCoverageNote(
  schedfileSheet,
  headerDates,
  mainDate,
  lateEmployeeName,
  lateType,
  originalShift,
  coveringName,
  coverageType,
  coverageDetails,
  sltDuty,
) {
  var lastRow = schedfileSheet.getLastRow();
  if (lastRow < 1) return;
  var namesInSchedfile = schedfileSheet.getRange("M1:M" + lastRow).getValues();
  var nameRow = -1;

  // Extract only the name part (strip trailing times, parentheses, or comments)
  var cleanCoveringName = coveringName
    ? coveringName
        .toString()
        .split(/\b\d|[(]/)[0]
        .trim()
    : "";

  for (var j = 0; j < namesInSchedfile.length; j++) {
    if (
      namesInSchedfile[j][0] &&
      cleanName(namesInSchedfile[j][0]) === cleanName(cleanCoveringName)
    ) {
      nameRow = j + 1;
      break;
    }
  }

  if (nameRow === -1) {
    Logger.log(
      "Covering employee '" +
        cleanCoveringName +
        "' (from input '" +
        coveringName +
        "') not found in Column L (late coverage).",
    );
    return;
  }

  // 1. Extract time range (e.g. "12:00 AM - 5:00 AM") from coveringName or coverageDetails
  var timeRegex =
    /\b\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*-\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)/i;
  var coverageShift = "";

  if (coveringName) {
    var timeMatchName = coveringName.toString().match(timeRegex);
    if (timeMatchName) {
      coverageShift = timeMatchName[0];
    }
  }

  if (!coverageShift && coverageDetails) {
    var timeMatchDetails = coverageDetails.toString().match(timeRegex);
    if (timeMatchDetails) {
      coverageShift = timeMatchDetails[0];
    }
  }

  // 2. Calculate if the coverage falls on the next calendar day (crossover shift)
  var finalDate = new Date(mainDate);
  if (coverageShift !== "" && originalShift) {
    var startHourMatch = coverageShift.match(
      /^\b(\d{1,2})(?::(\d{2}))?\s*(AM|PM|am|pm)?/i,
    );
    var lateStartHourMatch = originalShift
      .toString()
      .match(/^\b(\d{1,2})(?::(\d{2}))?\s*(AM|PM|am|pm)?/i);
    if (startHourMatch && lateStartHourMatch) {
      var startAmPm = startHourMatch[3] ? startHourMatch[3].toUpperCase() : "";
      var lateStartAmPm = lateStartHourMatch[3]
        ? lateStartHourMatch[3].toUpperCase()
        : "";
      // If late employee's shift starts in PM (e.g. 11PM) and coverage shift starts in AM
      if (lateStartAmPm === "PM" && startAmPm === "AM") {
        finalDate.setDate(finalDate.getDate() + 1);
      }
    }
  }

  // 3. Find date column index for finalDate
  var dateColumn = -1;
  for (var i = 0; i < headerDates.length; i++) {
    var schedfileDateValue = headerDates[i];
    if (schedfileDateValue instanceof Date) {
      var formattedSchedfileDate = Utilities.formatDate(
        schedfileDateValue,
        schedfileSheet.getParent().getSpreadsheetTimeZone(),
        "M/d/yyyy",
      );
      var formattedFinalDate = Utilities.formatDate(
        finalDate,
        schedfileSheet.getParent().getSpreadsheetTimeZone(),
        "M/d/yyyy",
      );

      if (formattedSchedfileDate === formattedFinalDate) {
        dateColumn = i + 1;
        break;
      }
    }
  }

  if (dateColumn === -1) {
    Logger.log("Date Column not found for late coverage date: " + finalDate);
    return;
  }

  var targetCell = schedfileSheet.getRange(nameRow, dateColumn);

  // 4. Coverer's own shift, read before we touch their cell. rawCellText stays untouched for
  // stacking; coveringOriginalShift may get fallback-substituted below (note-display only).
  var rawCellText = targetCell.getValue();
  rawCellText = rawCellText ? rawCellText.toString().trim() : "";
  var coveringOriginalShift = rawCellText;

  var isDSOT =
    coverageType &&
    coverageType.toString().toUpperCase().indexOf("DSOT") !== -1;

  // BACKUP/AGENT MODE: coverer is just standby, not an extra shift -- leave their value alone.
  // Strip whitespace before matching -- real data uses "BACK UP" (with a space), which
  // wouldn't match a plain "BACKUP" substring check.
  var normalizedCoverageType = coverageType
    ? coverageType.toString().toUpperCase().replace(/\s+/g, "")
    : "";
  var isBackupOrAgentMode =
    normalizedCoverageType.indexOf("BACKUP") !== -1 ||
    normalizedCoverageType.indexOf("AGENTMODE") !== -1;

  // DSOT: the typed time (e.g. "Juan (11:00 PM - 3:00 AM)") is the SEGMENT of the late
  // employee's shift this coverer handles, not their own shift. Falls back to the late
  // employee's full shift if untyped.
  var dsotCoverageShift = coverageShift
    ? coverageShift
    : originalShift
      ? originalShift.toString().trim()
      : "";

  // Blank cell (e.g. RDOT/DSOT day): non-DSOT falls back to a typed time, then to copying the
  // late employee's shift. DSOT stays blank -- the typed time is the segment above, not this.
  if (!coveringOriginalShift && !isDSOT) {
    if (coverageShift) {
      coveringOriginalShift = coverageShift;
    } else if (originalShift) {
      coveringOriginalShift = originalShift.toString().trim();
    }
  }

  var comment;
  if (isDSOT) {
    comment =
      "COVERAGE: " +
      lateEmployeeName +
      " (" +
      lateType.toUpperCase() +
      ")" +
      "\nORIGINAL SHIFT: " +
      coveringOriginalShift +
      "\nCOVERAGE SHIFT: " +
      dsotCoverageShift +
      "\nCOVERAGE TYPE: " +
      coverageType +
      "\n" +
      "\n" +
      sltDuty;
  } else {
    comment =
      "COVERAGE: " +
      lateEmployeeName +
      " (" +
      lateType.toUpperCase() +
      ")" +
      "\nCOVERAGE TYPE: " +
      coverageType +
      "\nCOVERAGE DETAILS: " +
      coverageDetails +
      "\n" +
      "\n" +
      sltDuty;
  }

  // Stack this shift onto rawCellText (never coveringOriginalShift, to avoid re-stacking an
  // already-stacked cell). Reuses stackShiftLines/parseStartMinutes from autoNoteAbsent.gs
  // (same GAS project, shared global scope). Skipped for BACKUP/AGENT MODE -- value stays as-is.
  if (!isBackupOrAgentMode) {
    var shiftToAdd = isDSOT
      ? dsotCoverageShift
      : originalShift
        ? originalShift.toString().trim()
        : "";
    var stackedValue = stackShiftLines(rawCellText, shiftToAdd);
    targetCell.setValue(stackedValue);
  }

  var existingCoverNote = targetCell.getNote();
  if (existingCoverNote && existingCoverNote.trim() !== "") {
    if (existingCoverNote.indexOf(comment.trim()) === -1) {
      targetCell.setNote(existingCoverNote.trim() + "\n\n" + comment);
    }
  } else {
    targetCell.setNote(comment);
  }
}

// Normalizes a name for comparison (case, spacing, trailing whitespace).
function cleanName(str) {
  if (!str) return "";
  return str.toString().toLowerCase().replace(/\s+/g, " ").trim();
}
