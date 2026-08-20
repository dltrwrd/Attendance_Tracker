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
        try {
          addNoteToSchedfile(rowNumber);
          sheet.getRange(rowNumber, triggerColumn).clearContent();
        } catch (rowError) {
          // Isolate this row's failure so one bad record doesn't block the rest of the batch.
          Logger.log(
            "Error firing absent row " + rowNumber + ": " + rowError.toString(),
          );
        }
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
    Logger.log(
      "addNoteToSchedfile row " + rowNumber + ": could not open external spreadsheet. " +
        e.message,
    );
    return;
  }

  var schedfileSheet = externalSs.getSheetByName(externalSheetName);
  if (!schedfileSheet) {
    Logger.log(
      "addNoteToSchedfile row " + rowNumber + ": sheet '" + externalSheetName +
        "' not found in external spreadsheet.",
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
  var coverage4 = mainSheet.getRange("X" + rowNumber).getValue();
  var coverageType4 = mainSheet.getRange("Y" + rowNumber).getValue();
  var coverageDetails4 = mainSheet.getRange("Z" + rowNumber).getValue();
  var sltDuty = mainSheet.getRange("AD" + rowNumber).getValue();

  if (!name || !dateStrMain) {
    Logger.log(
      "addNoteToSchedfile row " + rowNumber +
        ": missing Name (Column C) or Date (Column G).",
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
    Logger.log(
      "addNoteToSchedfile row " + rowNumber + ": date '" + dateStrMain +
        "' not found in header row of '" + externalSheetName + "'.",
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
    Logger.log(
      "addNoteToSchedfile row " + rowNumber + ": Employee ID '" + empId +
        "' not found in Column A of '" + externalSheetName + "'.",
    );
    return;
  }

  var targetCell = schedfileSheet.getRange(nameRow, dateColumn);
  var agent1Cell = targetCell; // live Range, used to paste-format-only onto the coverer's cell

  // Re-fire guard: if this row was already fired before, this cell's current format is our
  // own red "ABSENT" marker, not the true original. Don't propagate that to coverers -- null
  // it out so the format step is skipped for them (notes/values still update normally on
  // re-fire). Safe to do full-format copyTo now (not just background) since Agent1's own
  // overwrite is deferred until after all coverers are processed, below.
  if (
    targetCell.getValue() === "ABSENT" ||
    targetCell.getBackground().toUpperCase() === "#FF0000"
  ) {
    agent1Cell = null;
  }

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
    "  " +
    coverage4 +
    "\nCoverage Type: " +
    coverageType1 +
    "  " +
    coverageType2 +
    "  " +
    coverageType3 +
    "  " +
    coverageType4 +
    "\nCoverage Details: " +
    coverageDetails1 +
    "  " +
    coverageDetails2 +
    "  " +
    coverageDetails3 +
    "  " +
    coverageDetails4 +
    "\n" +
    "\n" +
    sltDuty;

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
        agent1Cell,
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
        agent1Cell,
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
          agent1Cell,
        );
      }
      if (
        coverage3 &&
        coverage3.toString().trim() !== "" &&
        !isNoRealCoverer(coverage3)
      ) {
        addAbsentCoverageNote(
          schedfileSheet,
          headerDates,
          mainDate,
          name,
          originalShift,
          coverage3,
          coverageType3,
          coverageDetails3,
          sltDuty,
          "ABSENT",
          agent1Cell,
        );
      }
      if (
        coverage4 &&
        coverage4.toString().trim() !== "" &&
        !isNoRealCoverer(coverage4)
      ) {
        addAbsentCoverageNote(
          schedfileSheet,
          headerDates,
          mainDate,
          name,
          originalShift,
          coverage4,
          coverageType4,
          coverageDetails4,
          sltDuty,
          "ABSENT",
          agent1Cell,
        );
      }
    }
  }

  // Overwrite Agent1's own cell LAST, after all coverers are colored using the true captured
  // original -- so no coverer ever sees this red "ABSENT" overwrite instead of the real color.
  targetCell.setNote(comment);
  targetCell.setValue("ABSENT");
  targetCell.setBackground("#FF0000"); // Red color
  targetCell.setFontColor("#FFFFFF"); // White font color
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
  var match = str.toString().match(/\b(\d{1,2})(?::(\d{2}))?\s*(AM|PM|am|pm)/i);
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
  agent1Cell,
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
    coverageType &&
    coverageType.toString().toUpperCase().indexOf("DSOT") !== -1;
  var isRDOT =
    coverageType &&
    coverageType.toString().toUpperCase().indexOf("RDOT") !== -1;

  // BACKUP/AGENT MODE: coverer is just standby, not an extra shift -- leave their value alone.
  // Strip whitespace before matching -- real data uses "BACK UP" (with a space), which
  // wouldn't match a plain "BACKUP" substring check.
  var normalizedCoverageType = coverageType
    ? coverageType.toString().toUpperCase().replace(/\s+/g, "")
    : "";
  var isBackupOrAgentMode =
    normalizedCoverageType.indexOf("BACKUP") !== -1 ||
    normalizedCoverageType.indexOf("AGENTMODE") !== -1;

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
  if (isDSOT || isRDOT) {
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

  // DSOT/RDOT: skip the format copy (coverer works their own shift elsewhere) unless their
  // cell was blank to begin with. BACKUP/AGENT MODE (and any other type) always copies. Full
  // paste-format-only (background, font family/size/weight/style/color, alignment, borders,
  // number format) via copyTo, like Ctrl+Alt+V.
  if (agent1Cell && (!(isDSOT || isRDOT) || coverCellWasBlank)) {
    agent1Cell.copyTo(targetCell, SpreadsheetApp.CopyPasteType.PASTE_FORMAT, false);
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
