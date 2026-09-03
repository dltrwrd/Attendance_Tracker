function autoNoteVTO(e) {
  var mainSheetName = "VTO";
  var triggerColumn = 18; // Column R
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
      addNoteToNotefile(rowNumber);
      range.clearContent();
    }
  }
}

function checkForFireTriggersVTO() {
  try {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var sheet = ss.getSheetByName("VTO");
    var triggerColumn = 18; // Column P

    if (!sheet) return;

    var lastRow = sheet.getLastRow();
    if (lastRow < 2) return;

    var dataRange = sheet.getRange(1, 1, lastRow, 20);
    var data = dataRange.getValues();

    for (var i = 1; i < data.length; i++) {
      var rowNumber = i + 1;
      var fireValue = data[i][17]; // Column P is index 15 (0-based)

      if (fireValue && fireValue.toString().toLowerCase() === "fire") {
        try {
          addNoteToNotefile(rowNumber);
          sheet.getRange(rowNumber, triggerColumn).clearContent();
        } catch (rowError) {
          // Isolate this row's failure so one bad record doesn't block the rest of the batch.
          Logger.log(
            "Error firing VTO row " + rowNumber + ": " + rowError.toString(),
          );
        }
      }
    }
  } catch (error) {
    Logger.log("Error in checkForFireTriggersVTO: " + error.toString());
  }
}

function setupAutoFireTriggerVTO() {
  // Remove any existing checkForFireTriggersVTO triggers first
  var triggers = ScriptApp.getProjectTriggers();
  triggers.forEach(function (trigger) {
    if (trigger.getHandlerFunction() === "checkForFireTriggersVTO") {
      ScriptApp.deleteTrigger(trigger);
    }
  });

  // Create new time-based trigger to run every 5 minutes
  ScriptApp.newTrigger("checkForFireTriggersVTO")
    .timeBased()
    .everyMinutes(1)
    .create();

  Browser.msgBox(
    "Success",
    "Auto-fire trigger setup complete! checkForFireTriggersVTO will run every 2 minutes.",
    Browser.Buttons.OK,
  );
}

function addNoteToNotefile(rowNumber) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var mainSheet = ss.getSheetByName("VTO");

  var externalSpreadsheetId = "1XeyCSc3_cgMWXev2b-2XXZkmbzw6gdrigU-va1jx920";
  var externalSheetName = "SEPT";

  var externalSs;
  try {
    externalSs = SpreadsheetApp.openById(externalSpreadsheetId);
  } catch (e) {
    Logger.log(
      "addNoteToNotefile row " +
        rowNumber +
        ": could not open external spreadsheet. " +
        e.message,
    );
    return;
  }

  var schedfileSheet = externalSs.getSheetByName(externalSheetName);
  if (!schedfileSheet) {
    Logger.log(
      "addNoteToNotefile row " +
        rowNumber +
        ": sheet '" +
        externalSheetName +
        "' not found in external spreadsheet.",
    );
    return;
  }

  var VTOType = mainSheet.getRange("O" + rowNumber).getValue();
  var VTOTime = mainSheet.getRange("L" + rowNumber).getDisplayValue();
  var name = mainSheet.getRange("D" + rowNumber).getDisplayValue();
  var dateStrMain = mainSheet.getRange("A" + rowNumber).getDisplayValue();
  var originalShift = mainSheet.getRange("H" + rowNumber).getValue();
  var Coverage = mainSheet.getRange("I" + rowNumber).getDisplayValue();
  var CoverageType = mainSheet.getRange("J" + rowNumber).getValue();
  var MinsWorked = mainSheet.getRange("M" + rowNumber).getValue();
  var VTOMins = mainSheet.getRange("N" + rowNumber).getValue();
  var Approved_by = mainSheet.getRange("P" + rowNumber).getValue();
  var sltDuty = mainSheet.getRange("Q" + rowNumber).getValue();

  if (!name || !dateStrMain) {
    Logger.log(
      "addNoteToNotefile row " +
        rowNumber +
        ": missing Name (Column D) or Date (Column A).",
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
      "addNoteToNotefile row " +
        rowNumber +
        ": date '" +
        dateStrMain +
        "' not found in header row of '" +
        externalSheetName +
        "'.",
    );
    return;
  }

  var empId = mainSheet.getRange("C" + rowNumber).getValue();
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
      "addNoteToNotefile row " +
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
  var vtoAgentCell = targetCell; // live Range, used to paste-format-only onto the coverer's cell

  // Re-fire guard: if this row was already fired before, this cell's current format is our
  // own cyan "VTO" marker, not the true original. Don't propagate that to coverers -- null it
  // out so the format step is skipped for them (notes/values still update normally on
  // re-fire). Safe to do full-format copyTo now (not just background) since this cell's own
  // overwrite is deferred until after the coverer is processed, below.
  var existingValue = targetCell.getValue();
  if (
    existingValue === "VTO" ||
    existingValue === "VTO - WD" ||
    targetCell.getBackground().toUpperCase() === "#00FFFF"
  ) {
    vtoAgentCell = null;
  }

  var comment =
    "VTO Type: " +
    VTOType +
    "\nVTO time: " +
    VTOTime +
    "\nOriginal Shift: " +
    originalShift +
    "\nCover By: " +
    Coverage +
    " " +
    CoverageType +
    "\nMinutes Worked: " +
    MinsWorked +
    " Mins" +
    "\nVTO minutes: " +
    VTOMins +
    " Mins" +
    "\n" +
    "\n" +
    "\nApproved by: " +
    Approved_by +
    "\n" +
    "\n" +
    sltDuty;

  // Auto-note for the person who covered (VTO). Reuses isNoRealCoverer from autoNoteAbsent.gs
  // (same GAS project, shared global scope) to skip placeholder text like "TAGGED AS (BACK UP)".
  if (
    Coverage &&
    Coverage.toString().trim() !== "" &&
    !isNoRealCoverer(Coverage)
  ) {
    addCoverageNote(
      schedfileSheet,
      headerDates,
      mainDate,
      name,
      originalShift,
      Coverage,
      CoverageType,
      "",
      sltDuty,
      "VTO",
      vtoAgentCell,
    );
  }

  // Overwrite this cell LAST, after the coverer is colored using the true captured original --
  // so the coverer never sees this cyan "VTO" overwrite instead of the real color.
  var existingNote = targetCell.getNote();
  if (existingNote && existingNote.trim() !== "") {
    // Append instead of overwrite so a late/undertime note written earlier isn't wiped out
    if (existingNote.indexOf(comment.trim()) === -1) {
      targetCell.setNote(existingNote.trim() + "\n\n" + comment);
    }
  } else {
    targetCell.setNote(comment);
  }
  // Set cell value based on MinsWorked
  if (MinsWorked === 0) {
    targetCell.setValue("VTO - WD");
  } else {
    targetCell.setValue("VTO");
  }
  targetCell.setBackground("#00FFFF");

  Logger.log(
    "Note added to cell " +
      targetCell.getA1Notation() +
      " in '" +
      externalSheetName +
      "' for " +
      name +
      " on " +
      dateStrMain +
      ".",
  );
}

// Writes coverage note + shift value + color on the coverer's cell in Schedfile.

function addCoverageNote(
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
  vtoAgentCell,
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

  // DSOT: the typed time (e.g. "Juan (11:00 PM - 3:00 AM)") is the SEGMENT of the VTO'd
  // employee's shift this coverer handles, not their own shift -- matters when multiple
  // coverers split one shift. Falls back to the VTO'd employee's full shift if untyped.
  var dsotCoverageShift = coverageShift
    ? coverageShift
    : originalShift
      ? originalShift.toString().trim()
      : "";

  // Blank cell (e.g. RDOT/DSOT day): non-DSOT falls back to a typed time, then to copying the
  // VTO'd employee's shift. DSOT stays blank -- the typed time is the segment above, not this.
  var usedFallbackShift = false;
  if (!coveringOriginalShift && !isDSOT) {
    if (coverageShift) {
      coveringOriginalShift = coverageShift;
    } else if (originalShift) {
      coveringOriginalShift = originalShift.toString().trim();
      usedFallbackShift = true;
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
  // already-stacked cell). Reuses stackShiftLines/parseStartMinutes from autoNoteAbsent.gs
  // (same GAS project, shared global scope). Skipped for BACKUP/AGENT MODE -- value stays as-is.
  if (!isBackupOrAgentMode) {
    var stackedValue;
    if (isDSOT) {
      stackedValue = stackShiftLines(rawCellText, dsotCoverageShift);
    } else if (statusType === "VTO") {
      // Straight backup taking over the exact shift, not an extra one -- copy, don't stack.
      stackedValue = originalShift;
    } else if (usedFallbackShift) {
      stackedValue = originalShift;
    } else {
      var ownStart = parseStartMinutes(coveringOriginalShift);
      var coveredStart = parseStartMinutes(originalShift);
      if (ownStart !== -1 && coveredStart !== -1 && coveredStart < ownStart) {
        stackedValue = originalShift + "\n" + coveringOriginalShift;
      } else {
        stackedValue = coveringOriginalShift + "\n" + originalShift;
      }
    }
    targetCell.setValue(stackedValue);
  }

  // DSOT/RDOT: skip the format copy (coverer works their own shift elsewhere) unless their
  // cell was blank to begin with. BACKUP/AGENT MODE (and any other type) always copies. Full
  // paste-format-only (background, font family/size/weight/style/color, alignment, borders,
  // number format) via copyTo, like Ctrl+Alt+V.
  if (vtoAgentCell && (!(isDSOT || isRDOT) || coverCellWasBlank)) {
    vtoAgentCell.copyTo(
      targetCell,
      SpreadsheetApp.CopyPasteType.PASTE_FORMAT,
      false,
    );
  }

  // Compare on coverageNoteKey (from autoNoteAbsent.gs, shared global scope), not the raw
  // comment -- the "ORIGINAL SHIFT:"/"SHIFT:" line is read from this cell and rewritten above,
  // so a re-fire would otherwise never match and would append a duplicate.
  var existingNote = targetCell.getNote();
  if (existingNote && existingNote.trim() !== "") {
    if (
      coverageNoteKey(existingNote).indexOf(coverageNoteKey(comment)) !== -1
    ) {
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
