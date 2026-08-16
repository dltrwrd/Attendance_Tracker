function autoNoteAbsent(e) {
  var mainSheetName = "ABSENTEEISM";
  var triggerColumn = 32; // Column AF
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
      addNoteToSchedfile(rowNumber);
      range.clearContent();
    }
  }
}

function checkForFireTriggersAbsent() {
  try {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var sheet = ss.getSheetByName("ABSENTEEISM");
    if (!sheet) return;

    var lastRow = sheet.getLastRow();
    if (lastRow < 2) return;

    var triggerColumn = 32; // Column AF (1-based index)
    var triggerIndex = triggerColumn - 1; // 31 (0-based index for array)

    // Expand the range to 32 columns so Column AF is included
    var dataRange = sheet.getRange(1, 1, lastRow, triggerColumn);
    var data = dataRange.getValues();

    for (var i = 1; i < data.length; i++) {
      var rowNumber = i + 1;
      var fireValue = data[i][triggerIndex]; // Reads Column AF

      if (fireValue && fireValue.toString().trim().toLowerCase() === "fire") {
        addNoteToSchedfile(rowNumber);
        sheet.getRange(rowNumber, triggerColumn).clearContent();
      }
    }
  } catch (error) {
    Logger.log("Error in checkForFireTriggersAbsent: " + error.toString());
  }
}

function setupAutoFireTriggerAbsent() {
  // Remove any existing checkForFireTriggersAbsent triggers first
  var triggers = ScriptApp.getProjectTriggers();
  triggers.forEach(function (trigger) {
    if (trigger.getHandlerFunction() === "checkForFireTriggersAbsent") {
      ScriptApp.deleteTrigger(trigger);
    }
  });

  // Create new time-based trigger to run every 5 minutes
  ScriptApp.newTrigger("checkForFireTriggersAbsent")
    .timeBased()
    .everyMinutes(5)
    .create();
}

function addNoteToSchedfile(rowNumber) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var mainSheet = ss.getSheetByName("ABSENTEEISM");

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

  var name = mainSheet.getRange("C" + rowNumber).getValue();
  var dateStrMain = mainSheet.getRange("G" + rowNumber).getDisplayValue();
  var sanction = mainSheet.getRange("I" + rowNumber).getDisplayValue();
  var originalShift = mainSheet.getRange("AA" + rowNumber).getValue();
  var reason = mainSheet.getRange("J" + rowNumber).getValue();
  var coverage1 = mainSheet.getRange("L" + rowNumber).getValue();
  var coverageType1 = mainSheet.getRange("M" + rowNumber).getValue();
  var coverageDetails1 = mainSheet.getRange("N" + rowNumber).getValue(); // Get Column M Coverage Shift/Details
  var coverage2 = mainSheet.getRange("P" + rowNumber).getValue();
  var coverageType2 = mainSheet.getRange("Q" + rowNumber).getValue();
  var coverageDetails2 = mainSheet.getRange("R" + rowNumber).getValue();
  var coverage3 = mainSheet.getRange("T" + rowNumber).getValue();
  var coverageType3 = mainSheet.getRange("U" + rowNumber).getValue();
  var coverageDetails3 = mainSheet.getRange("V" + rowNumber).getValue();
  var sltDuty = mainSheet.getRange("AD" + rowNumber).getValue();

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

  var targetCell = schedfileSheet.getRange(nameRow, dateColumn);
  var agent1Color = targetCell.getBackground(); // capture BEFORE overwrite, used to color the coverer's cell

  var comment =
    sanction +
    "\n" +
    "\n" +
    "Original Shift: " +
    originalShift +
    "\nReason: " +
    reason +
    "\nCoverage: " +
    coverage1 +
    "  " +
    coverage2 +
    " " +
    coverage3 +
    "\nCoverage Type: " +
    coverageType1 +
    "  " +
    coverageType2 +
    "  " +
    coverageType2 +
    "\nCoverage Details: " +
    coverageDetails1 +
    "  " +
    coverageDetails2 +
    "  " +
    coverageDetails3 +
    "\n" +
    "\n" +
    sltDuty;

  targetCell.setNote(comment);
  targetCell.setValue("ABSENT");
  targetCell.setBackground("#FF0000"); // Red color
  targetCell.setFontColor("#FFFFFF"); // White font color

  // Auto-note for the people who covered (Absenteeism)
  var cleanCovName1 = coverage1
    ? coverage1
        .toString()
        .split(/\b\d|[(]/)[0]
        .trim()
    : "";
  var cleanCovName2 = coverage2
    ? coverage2
        .toString()
        .split(/\b\d|[(]/)[0]
        .trim()
    : "";

  if (cleanCovName1 && cleanCovName1 !== "" && !isNoRealCoverer(coverage1)) {
    if (
      cleanCovName2 &&
      cleanCovName2 !== "" &&
      cleanName(cleanCovName1) === cleanName(cleanCovName2)
    ) {
      // Both coverages are the same person! Merge details.
      var timeRegex =
        /\b\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)?\s*-\s*\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)/i;

      var time1 = "";
      var timeMatch1 = coverage1.toString().match(timeRegex);
      if (timeMatch1) {
        time1 = timeMatch1[0];
      } else if (coverageDetails1) {
        var timeMatchDetails1 = coverageDetails1.toString().match(timeRegex);
        if (timeMatchDetails1) time1 = timeMatchDetails1[0];
      }

      var time2 = "";
      var timeMatch2 = coverage2.toString().match(timeRegex);
      if (timeMatch2) {
        time2 = timeMatch2[0];
      } else if (coverageDetails2) {
        var timeMatchDetails2 = coverageDetails2.toString().match(timeRegex);
        if (timeMatchDetails2) time2 = timeMatchDetails2[0];
      }

      var mergedShift = time1 + " | " + time2;
      var mergedType = coverageType1 + " | " + coverageType2;

      addAbsentCoverageNote(
        schedfileSheet,
        headerDates,
        mainDate,
        name,
        originalShift,
        cleanCovName1,
        mergedType,
        mergedShift,
        sltDuty,
        "ABSENT",
        agent1Color,
      );
    } else {
      // Different people or only one coverage person
      addAbsentCoverageNote(
        schedfileSheet,
        headerDates,
        mainDate,
        name,
        originalShift,
        coverage1,
        coverageType1,
        coverageDetails1,
        sltDuty,
        "ABSENT",
        agent1Color,
      );
      if (
        coverage2 &&
        coverage2.toString().trim() !== "" &&
        !isNoRealCoverer(coverage2)
      ) {
        addAbsentCoverageNote(
          schedfileSheet,
          headerDates,
          mainDate,
          name,
          originalShift,
          coverage2,
          coverageType2,
          coverageDetails2,
          sltDuty,
          "ABSENT",
          agent1Color,
        );
      }
    }
  }
}

// True when a coverage field is a placeholder note (e.g. "TAGGED AS (BACK UP)", "NO NEED
// (TRAINEE)") instead of an actual coverer's name -- nothing to look up or note for these.
function isNoRealCoverer(text) {
  if (!text) return true;
  var upper = text.toString().trim().toUpperCase();
  return upper.indexOf("NO NEED") === 0 || upper.indexOf("TAGGED AS") === 0;
}

// Shift string -> minutes-since-midnight of its first HH:MM AM/PM, or -1 if unparseable (e.g. "RDOT").
function parseStartMinutes(str) {
  if (!str) return -1;
  var match = str
    .toString()
    .match(/\b(\d{1,2})(?::(\d{2}))?\s*(AM|PM|am|pm)/i);
  if (!match) return -1;
  var hour = parseInt(match[1], 10);
  var minute = match[2] ? parseInt(match[2], 10) : 0;
  var ampm = match[3].toUpperCase();
  if (ampm === "PM" && hour !== 12) hour += 12;
  if (ampm === "AM" && hour === 12) hour = 0;
  return hour * 60 + minute;
}

// Adds newShiftText to rawCellText's lines, deduped and sorted by start time (unparseable
// lines like "RDOT" sort last). Must take the RAW cell text, not a fallback-substituted
// value, or repeat fires re-stack an already-stacked cell and grow it forever.
function stackShiftLines(rawCellText, newShiftText) {
  var lines = rawCellText
    ? rawCellText
        .split("\n")
        .map(function (s) {
          return s.trim();
        })
        .filter(function (s) {
          return s !== "";
        })
    : [];

  if (!newShiftText) return lines.join("\n");
  if (lines.indexOf(newShiftText) !== -1) return lines.join("\n");

  lines.push(newShiftText);
  lines.sort(function (a, b) {
    var aStart = parseStartMinutes(a);
    var bStart = parseStartMinutes(b);
    if (aStart === -1 && bStart === -1) return 0;
    if (aStart === -1) return 1;
    if (bStart === -1) return -1;
    return aStart - bStart;
  });
  return lines.join("\n");
}

// Writes coverage note + shift value + color on the coverer's cell in Schedfile.
function addAbsentCoverageNote(
  schedfileSheet,
  headerDates,
  mainDate,
  absentEmployeeName,
  originalShift,
  coveringName,
  coverageType,
  coverageDetails,
  sltDuty,
  statusType,
  agent1Color,
) {
  var lastRow = schedfileSheet.getLastRow();
  if (lastRow < 1) return;
  var namesInSchedfile = schedfileSheet.getRange("L1:L" + lastRow).getValues();
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
        "') not found in Column L.",
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
    var absentStartHourMatch = originalShift
      .toString()
      .match(/^\b(\d{1,2})(?::(\d{2}))?\s*(AM|PM|am|pm)?/i);
    if (startHourMatch && absentStartHourMatch) {
      var startAmPm = startHourMatch[3] ? startHourMatch[3].toUpperCase() : "";
      var absentStartAmPm = absentStartHourMatch[3]
        ? absentStartHourMatch[3].toUpperCase()
        : "";
      // If absent shift starts in PM (e.g. 11PM) and coverage shift starts in AM (e.g. 2AM)
      if (absentStartAmPm === "PM" && startAmPm === "AM") {
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
    Logger.log("Date Column not found for coverage date: " + finalDate);
    return;
  }

  var targetCell = schedfileSheet.getRange(nameRow, dateColumn);

  // 4. Coverer's own shift, read before we touch their cell. rawCellText stays untouched for
  // stacking; coveringOriginalShift may get fallback-substituted below (note-display only).
  var rawCellText = targetCell.getValue();
  rawCellText = rawCellText ? rawCellText.toString().trim() : "";
  var coveringOriginalShift = rawCellText;
  var coverCellWasBlank = !coveringOriginalShift;

  var isDSOT =
    coverageType && coverageType.toString().toUpperCase().indexOf("DSOT") !== -1;

  // BACKUP/AGENT MODE: coverer is just standby, not an extra shift -- leave their value alone.
  var isBackupOrAgentMode =
    coverageType &&
    (coverageType.toString().toUpperCase().indexOf("BACKUP") !== -1 ||
      coverageType.toString().toUpperCase().indexOf("AGENT MODE") !== -1);

  // DSOT: the typed time (e.g. "Juan (11:00 PM - 3:00 AM)") is the SEGMENT of the absent
  // employee's shift this coverer handles, not their own shift -- matters when multiple
  // coverers split one shift. Falls back to the absent employee's full shift if untyped.
  var dsotCoverageShift = coverageShift
    ? coverageShift
    : originalShift
      ? originalShift.toString().trim()
      : "";

  // Blank cell (e.g. RDOT/DSOT day): non-DSOT falls back to a typed time, then to copying the
  // absent employee's shift. DSOT stays blank -- the typed time is the segment above, not this.
  if (!coveringOriginalShift && !isDSOT) {
    if (coverageShift) {
      coveringOriginalShift = coverageShift;
    } else if (originalShift) {
      coveringOriginalShift = originalShift.toString().trim();
    }
  }

  var comment = "";
  if (isDSOT) {
    comment =
      "COVERAGE: " +
      absentEmployeeName +
      " (" +
      statusType +
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
  } else if (statusType === "VTO") {
    comment =
      "COVERAGE: " +
      absentEmployeeName +
      " (VTO)" +
      "\nSHIFT: " +
      coveringOriginalShift +
      "\nCOVERAGE TYPE: " +
      coverageType +
      "\n" +
      "\n" +
      sltDuty;
  } else {
    // Absent
    if (coverageShift !== "") {
      // With interval (Half coverage)
      comment =
        "COVERAGE: " +
        absentEmployeeName +
        " (ABSENT)" +
        "\nORIGINAL SHIFT: " +
        coveringOriginalShift +
        "\nCOVERAGE SHIFT: " +
        coverageShift +
        "\nCOVERAGE TYPE: " +
        coverageType +
        "\n" +
        "\n" +
        sltDuty;
    } else {
      // No interval (Solo/malinis coverage)
      comment =
        "COVERAGE: " +
        absentEmployeeName +
        " (ABSENT)" +
        "\nSHIFT: " +
        coveringOriginalShift +
        "\nCOVERAGE TYPE: " +
        coverageType +
        "\n" +
        "\n" +
        sltDuty;
    }
  }

  // Stack this shift onto rawCellText (never coveringOriginalShift, to avoid re-stacking an
  // already-stacked cell). Skipped for BACKUP/AGENT MODE -- value stays as-is.
  if (!isBackupOrAgentMode) {
    var shiftToAdd = isDSOT
      ? dsotCoverageShift
      : originalShift
        ? originalShift.toString().trim()
        : "";
    var stackedValue = stackShiftLines(rawCellText, shiftToAdd);
    targetCell.setValue(stackedValue);
  }

  // DSOT: skip the color copy (coverer works their own shift elsewhere) unless their cell
  // was blank to begin with.
  if (agent1Color && (!isDSOT || coverCellWasBlank)) {
    targetCell.setBackground(agent1Color);
  }

  var existingNote = targetCell.getNote();
  if (existingNote && existingNote.trim() !== "") {
    // Check if the exact comment is already present to prevent duplication
    if (existingNote.indexOf(comment.trim()) !== -1) {
      Logger.log("Coverage note already exists on cell. Skipping duplication.");
      return;
    }
    targetCell.setNote(existingNote.trim() + "\n\n" + comment);
  } else {
    targetCell.setNote(comment);
  }
}

// Normalizes a name for comparison (case, spacing, trailing whitespace).
function cleanName(str) {
  if (!str) return "";
  return str.toString().toLowerCase().replace(/\s+/g, " ").trim();
}
