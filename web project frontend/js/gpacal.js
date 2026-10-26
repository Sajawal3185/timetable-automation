
const gradePoints = {
  "A+": 4.00,
  "A": 4.00,
  "B+": 3.50,
  "B": 3.00,
  "C+": 2.50,
  "C": 2.00,
  "D+": 1.50,
  "D": 1.00,
  "F": 0.00
};

const courseRows = document.getElementById("course-rows");

let calculatedGPA = null;
let calculatedCredits = 0;

// Create grade options
function makeGradeOptions(selected = "A") {
  return Object.keys(gradePoints)
    .map(grade => `
      <option value="${grade}" ${grade === selected ? "selected" : ""}>
        ${grade} (${gradePoints[grade].toFixed(2)})
      </option>
    `)
    .join("");
}

// Add a new course row
function addCourse(name = "", grade = "A", credits = "3") {
  const row = document.createElement("tr");

  row.innerHTML = `
    <td class="row-number"></td>

    <td>
      <input
        class="course-name"
        type="text"
        aria-label="Course name"
        placeholder="e.g. Enter Subject"
        value="${name}"
      >
    </td>

    <td>
      <select class="grade-select" aria-label="Grade">
        ${makeGradeOptions(grade)}
      </select>
    </td>

    <td>
      <input
  class="credit-input"
  type="number"
  aria-label="Credit hours"
  min="1"
  max="3"
  step="1"
  value="${credits}"
  required
>
    </td>

    <td>
      <button
        class="remove-course"
        type="button"
        aria-label="Remove course"
      >×</button>
    </td>
  `;

  row.querySelector(".remove-course").addEventListener("click", () => {
    row.remove();
    updateRowNumbers();
  });

  courseRows.appendChild(row);
  updateRowNumbers();
}

// Update course numbers
function updateRowNumbers() {
  const rows = courseRows.querySelectorAll("tr");

  rows.forEach((row, index) => {
    row.querySelector(".row-number").textContent = index + 1;
  });
}

// Calculate semester GPA
function calculateSemesterGPA() {
  const error = document.getElementById("gpa-error");
  error.textContent = "";

  const rows = [...courseRows.querySelectorAll("tr")];

  if (rows.length === 0) {
    error.textContent = "Please add at least one course.";
    return;
  }

  let totalCredits = 0;
  let totalQualityPoints = 0;

  for (let i = 0; i < rows.length; i++) {
    const name = rows[i]
      .querySelector(".course-name")
      .value.trim();

    const grade = rows[i]
      .querySelector(".grade-select").value;

    const credits = Number(
      rows[i].querySelector(".credit-input").value
    );

    if (name === "") {
      error.textContent = `Please enter a name for course ${i + 1}.`;
      rows[i].querySelector(".course-name").focus();
      return;
    }

    if (!Number.isFinite(credits) || credits <= 0) {
      error.textContent = `Enter valid credit hours for ${name}.`;
      rows[i].querySelector(".credit-input").focus();
      return;
    }

    totalCredits += credits;
    totalQualityPoints += gradePoints[grade] * credits;
  }

  if (totalCredits <= 0) {
    error.textContent = "Total credit hours must be greater than zero.";
    return;
  }

  calculatedGPA = totalQualityPoints / totalCredits;
  calculatedCredits = totalCredits;

  document.getElementById("gpa-result").textContent =
    calculatedGPA.toFixed(2);

  document.getElementById("total-credits").textContent =
    totalCredits.toFixed(1).replace(/\.0$/, "");

  document.getElementById("total-quality-points").textContent =
    totalQualityPoints.toFixed(2);

  document.getElementById("gpa-message").textContent =
    "Semester GPA calculated successfully.";

  document.getElementById("current-gpa").value =
    calculatedGPA.toFixed(2);

  document.getElementById("current-credits").value =
    totalCredits;
}

// Add course button
document.getElementById("add-course").addEventListener("click", () => {
  addCourse();
});

// Calculate GPA button
document.getElementById("calculate-gpa").addEventListener("click", () => {
  calculateSemesterGPA();
});

// Switch between GPA and CGPA sections
document.querySelectorAll(".tab").forEach(tab => {
  tab.addEventListener("click", () => {
    document.querySelectorAll(".tab").forEach(item => {
      item.classList.remove("active");
    });

    tab.classList.add("active");

    const showGPA = tab.dataset.tab === "gpa";

    document.getElementById("gpa-panel")
      .classList.toggle("hidden", !showGPA);

    document.getElementById("cgpa-panel")
      .classList.toggle("hidden", showGPA);
  });
});

// Transfer calculated GPA to the CGPA form
document.getElementById("use-current-gpa").addEventListener("click", () => {
  const error = document.getElementById("cgpa-error");

  if (calculatedGPA === null) {
    error.textContent = "Calculate your semester GPA first.";
    return;
  }

  error.textContent = "";

  document.getElementById("current-gpa").value =
    calculatedGPA.toFixed(2);

  document.getElementById("current-credits").value =
    calculatedCredits;
});

// Calculate cumulative CGPA
document.getElementById("cgpa-form").addEventListener("submit", event => {
  event.preventDefault();

  const error = document.getElementById("cgpa-error");
  error.textContent = "";

  const previousCGPA = Number(
    document.getElementById("previous-cgpa").value
  );

  const previousCredits = Number(
    document.getElementById("previous-credits").value
  );

  const currentGPA = Number(
    document.getElementById("current-gpa").value
  );

  const currentCredits = Number(
    document.getElementById("current-credits").value
  );

  if (
    !Number.isFinite(previousCGPA) ||
    previousCGPA < 0 ||
    previousCGPA > 4 ||
    !Number.isFinite(currentGPA) ||
    currentGPA < 0 ||
    currentGPA > 4 ||
    !Number.isFinite(previousCredits) ||
    previousCredits < 0 ||
    !Number.isFinite(currentCredits) ||
    currentCredits <= 0
  ) {
    error.textContent =
      "Check your values. GPA/CGPA must be between 0 and 4. " +
      "Previous credits cannot be negative, and current credits must be greater than zero.";
    return;
  }

  const totalCredits = previousCredits + currentCredits;

  if (totalCredits <= 0) {
    error.textContent = "Total credit hours must be greater than zero.";
    return;
  }

  const newCGPA =
    (
      previousCGPA * previousCredits +
      currentGPA * currentCredits
    ) / totalCredits;

  document.getElementById("cgpa-result").textContent =
    newCGPA.toFixed(2);

  document.getElementById("cgpa-message").textContent =
    `Based on ${totalCredits} total credit hours.`;
});

// Initial example courses
addCourse();
