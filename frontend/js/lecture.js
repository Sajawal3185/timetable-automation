
const $ = id => document.getElementById(id);

// ---------- Departments ----------
const DEPARTMENTS = [
  { code: "SE", name: "Software Engineering" },
  { code: "AI", name: "Artificial Intelligence" },
  { code: "CS", name: "Computer Science" },
  { code: "IT", name: "Information Technology" }
];

// ---------- Subjects ----------
const SUBJECTS = [];

function addSubjects(dept, list) {
  list.forEach(name => {
    SUBJECTS.push({ dept, name });
  });
}

addSubjects("SE", [
  "Application of Information and Communication Technology",
  "Programming Fundamentals",
  "Calculus and Analytical Geometry",
  "Discrete Structures",
  "Object Oriented Programming",
  "Software Engineering",
  "Data Structure and Algorithms",
  "Linear Algebra",
  "Operating Systems",
  "Software Design & Architecture",
  "Formal Methods in SE",
  "Database Systems",
  "Business Process Engineering",
  "Software Requirements Engineering",
  "Probability & Statistics",
  "Software Construction & Development",
  "Artificial Intelligence",
  "Human Computer Interaction",
  "Computer Networks",
  "Visual Programming",
  "Information Security",
  "Software Quality Engineering",
  "Web Engineering",
  "Mobile App Development",
  "Software Re-Engineering",
  "Software Project Management",
  "Operation Research",
  "Game Development",
  "Software Process Improvement",
  "Web Engineering II",
  "Applied Physics",
  "Functional English",
  "Islamic Studies",
  "Ideology and Constitution of Pakistan",
  "Expository Writing",
  "Introduction to Psychology",
  "Technical & Business Writing",
  "Professional Practices",
  "Entrepreneurship",
  "Social Service",
  "Accounting Fundamentals"
]);

addSubjects("CS", [
  "Application of Info. & Comm. Tech.",
  "Functional English",
  "Islamic Studies",
  "Applied Physics",
  "Pakistan Studies",
  "Programming Fundamentals",
  "Math-I",
  "Calculus and Analytical Geometry",
  "Expository Writing",
  "Civics and Community Engagement",
  "Discrete Structures",
  "Object Oriented Programming",
  "Ideology and Constitution of Pakistan",
  "Math-II",
  "Digital Marketing",
  "Fehm-e-Quran-I",
  "Digital Logic Design",
  "Data Structures",
  "Database System",
  "Linear Algebra",
  "Professional Practices",
  "Artificial Intelligence",
  "Software Engineering",
  "Comp. Org. & Assembly Language",
  "Web-Technology",
  "Technology Enterpreneurship",
  "Fehm-e-Quran-II",
  "Computer Architecture",
  "Analysis of Algorithms",
  "Multi Variable Calculus",
  "Statistics & Probability",
  "Operating System",
  "Web Engineering",
  "Theory of Automata",
  "Computer Networks",
  "Numerical Analysis",
  "Advanced Programming (VP)",
  "Adv. Database Management System",
  "Technical & Business Writing",
  "HCI & Computer Graphics",
  "Compiler Construction",
  "Parallel & Distributed Computing",
  "Software Testing & Quality Assurance",
  "Mobile Application Development",
  "Foreign Language (Chinese / Japanese / French / Arabic / German)",
  "Cloud Computing",
  "Information Security",
  "Financial Management"
]);

addSubjects("AI", [
  "Application of Information & Communication Technology",
  "Programming Fundamentals",
  "Functional English",
  "Discrete Structures",
  "Applied Physics",
  "Islamic Studies",
  "Maths-I",
  "Object Oriented Programming",
  "Calculus and Analytical Geometry",
  "Digital Logic Design",
  "Expository Writing",
  "Civics & Community Engagement",
  "Ideology and Constitution of Pakistan",
  "Pre-Maths-II",
  "Data Structures",
  "Programming for Artificial Intelligence",
  "Professional Practices",
  "Statistics & Probability",
  "Multivariable Calculus",
  "Computer Networks",
  "Digital Marketing",
  "Advanced Statistics",
  "Analysis of Algorithms",
  "Artificial Intelligence",
  "Database Systems",
  "Linear Algebra",
  "Artificial Neural Network",
  "Software Engineering",
  "Machine Learning",
  "Knowledge Representation & Reasoning",
  "Computer Organization & Assembly Language",
  "Accounting Fundamentals",
  "Operating System",
  "Computer Vision",
  "Natural Language Processing",
  "Data Mining",
  "Technical and Business Writing",
  "Parallel & Distributed Computing",
  "HCI & Computer Graphics",
  "Theory of Automata",
  "Fuzzy Systems",
  "Reinforcement Learning",
  "Technology Entrepreneurship",
  "Information Security"
]);

addSubjects("IT", [
  "Programming Fundamentals",
  "Application to Information & Communication Technology",
  "Islamic Studies",
  "Applied Physics",
  "Functional English",
  "Human Resource Management",
  "Expository Writing",
  "Object Oriented Programming",
  "Discrete Structures",
  "Software Engineering",
  "Calculus & Analytical Geometry",
  "Pakistan Studies",
  "Introduction to Psychology",
  "Data Structures",
  "Linear Algebra",
  "Enterprise System",
  "Professional Practices",
  "Computer Networks",
  "Ideology and Constitution of Pakistan",
  "Database Systems",
  "Operating Systems",
  "Software Requirement Engineering",
  "Multivariable Calculus",
  "Entrepreneurship",
  "Civics and Community Engagement",
  "System & Network Administration",
  "Database Administration & Management",
  "Digital Logic Design",
  "Analysis of Algorithm",
  "Information Security",
  "Digital Marketing",
  "Technical & Business Writing",
  "IT Infrastructure",
  "Artificial Intelligence",
  "Web Technologies",
  "Probability & Statistics",
  "Mobile Application Development",
  "Software Project Management",
  "Parallel & Distributed Computing",
  "Computer Organization & Assembly Language",
  "Virtual Systems and Services",
  "Cyber Security",
  "Fundamentals of DevOps",
  "Foreign Language"
]);

// ---------- Demo Records ----------
const lectures = [
  {
    id: 1,
    dept: "SE",
    subjectName: "Data Structure and Algorithms",
    title: "Trees and Graphs",
    year: 2024,
    fileName: "dsa-trees.pdf",
    fileUrl: "../lectures/dsa-trees.pdf",
    status: "approved"
  },
  {
    id: 2,
    dept: "SE",
    subjectName: "Data Structure and Algorithms",
    title: "Stacks and Queues",
    year: 2023,
    fileName: "dsa-stacks.pdf",
    fileUrl: "../lectures/dsa-stacks.pdf",
    status: "approved"
  },
  {
    id: 3,
    dept: "SE",
    subjectName: "Operating Systems",
    title: "Process Scheduling",
    year: 2024,
    fileName: "os-scheduling.pdf",
    fileUrl: "../lectures/os-scheduling.pdf",
    status: "approved"
  }
];

// ---------- Dropdown Helpers ----------
function fill(select, items, placeholder) {
  select.replaceChildren();

  const first = new Option(placeholder, "");
  first.disabled = true;
  first.selected = true;
  select.add(first);

  items.forEach(item => {
    select.add(new Option(item.label, String(item.value)));
  });

  select.value = "";
}

function lock(control, locked) {
  control.disabled = locked;
  control.classList.toggle("locked", locked);
}

// Department names only.
function getDepartmentOptions() {
  return DEPARTMENTS.map(dept => ({
    value: dept.code,
    label: dept.name
  }));
}

// Subject names only. No course codes.
function getSubjectOptions(dept) {
  return SUBJECTS
    .filter(subject => subject.dept === dept)
    .map(subject => ({
      value: subject.name,
      label: subject.name
    }));
}

// ---------- Approved Lectures ----------
function approvedFor(dept, subjectName) {
  return lectures.filter(lecture =>
    lecture.status === "approved" &&
    lecture.dept === dept &&
    lecture.subjectName === subjectName
  );
}

// ---------- Year Options ----------
function getYearOptions(dept, subjectName) {
  const currentYear = new Date().getFullYear();

  const years = [];

  for (let year = 2020; year <= currentYear; year++) {
    years.push({
      value: year,
      label: String(year)
    });
  }

  return years.reverse();
}
// ---------- Title Search ----------
function normalizeWord(word) {
  let result = word.toLowerCase();

  if (result.length > 4 && result.endsWith("ies")) {
    result = result.slice(0, -3) + "y";
  } else if (
    result.length > 4 &&
    /(ches|shes|xes|zes|ses|sses)$/.test(result)
  ) {
    result = result.slice(0, -2);
  } else if (
    result.length > 3 &&
    result.endsWith("s") &&
    !result.endsWith("ss")
  ) {
    result = result.slice(0, -1);
  }

  return result;
}

function normalizeTitle(value) {
  const commonWords = [
    "and", "the", "of", "a", "an",
    "for", "to", "in", "on", "with"
  ];

  return String(value || "")
    .toLowerCase()
    .replace(/&/g, " and ")
    .replace(/[^a-z0-9\s]/g, " ")
    .split(/\s+/)
    .filter(word => word && !commonWords.includes(word))
    .map(normalizeWord);
}

function titleMatches(lectureTitle, searchText) {
  const searchWords = normalizeTitle(searchText);

  if (searchWords.length === 0) {
    return true;
  }

  const titleWords = normalizeTitle(lectureTitle);

  // Search words can be in any order.
  // Singular/plural and word beginnings are supported.
  return searchWords.every(searchWord =>
    titleWords.some(titleWord =>
      titleWord === searchWord ||
      titleWord.startsWith(searchWord)
    )
  );
}

// ---------- Filter Initial State ----------
function initFilters() {
  fill($("fDept"), getDepartmentOptions(), "Choose Department");
  fill($("fSubject"), [], "Choose Subject");
  fill($("fYear"), [], "Choose Year");

  $("fTitle").value = "";

  lock($("fDept"), false);
  lock($("fSubject"), true);
  lock($("fYear"), true);
  lock($("fTitle"), true);
}

// ---------- Display Lectures ----------
function showLectures() {
  const dept = $("fDept").value;
  const subjectName = $("fSubject").value;
  const year = $("fYear").value;
  const titleFilter = $("fTitle").value.trim();

  const list = $("list");
  list.replaceChildren();

  // Department, subject and year are required.
  if (!dept || !subjectName || !year) {
    $("hint").hidden = false;
    $("empty").hidden = true;
    return;
  }

  $("hint").hidden = true;

  const results = approvedFor(dept, subjectName).filter(lecture =>
    String(lecture.year) === year &&
    titleMatches(lecture.title, titleFilter)
  );

  results.forEach(lecture => {
    const card = document.createElement("article");
    card.className = "paper-card";

    const title = document.createElement("h3");
    title.textContent = lecture.title;

    const course = document.createElement("p");
    course.textContent = lecture.subjectName;

    const yearLine = document.createElement("p");
    yearLine.textContent = `Year: ${lecture.year}`;

    const fileName = document.createElement("p");
    fileName.textContent = lecture.fileName;

    const actions = document.createElement("div");
    actions.className = "actions";

    const view = document.createElement("a");
    view.href = lecture.fileUrl;
    view.target = "_blank";
    view.rel = "noopener noreferrer";
    view.className = "btn";
    view.textContent = "View Slides";

    const download = document.createElement("a");
    download.href = lecture.fileUrl;
    download.download = lecture.fileName;
    download.className = "btn solid";
    download.textContent = "Download";

    actions.append(view, download);

    card.append(
      title,
      course,
      yearLine,
      fileName,
      actions
    );

    list.append(card);
  });

  $("empty").hidden = results.length > 0;
}

// ---------- Filter Event Listeners ----------
function setupFilters() {
  $("fDept").addEventListener("change", () => {
    const dept = $("fDept").value;

    fill($("fSubject"), getSubjectOptions(dept), "Choose Subject");
    fill($("fYear"), [], "Choose Year");

    $("fTitle").value = "";

    lock($("fSubject"), !dept);
    lock($("fYear"), true);
    lock($("fTitle"), true);

    showLectures();
  });
$("fSubject").addEventListener("change", () => {
  const dept = $("fDept").value;
  const subjectName = $("fSubject").value;

  const years = getYearOptions(dept, subjectName);

  fill($("fYear"), years, "Choose Year");

  $("fTitle").value = "";

  // Unlock Year whenever a subject is selected.
  lock($("fYear"), !subjectName);

  lock($("fTitle"), true);

  $("hint").textContent = years.length === 0
    ? "No lectures uploaded for this subject yet. Select a year if available."
    : "Choose a year. You can also search by lecture title.";

  $("hint").hidden = false;

  showLectures();
});

  $("fYear").addEventListener("change", () => {
    $("fTitle").value = "";

    lock($("fTitle"), !$("fYear").value);

    showLectures();
  });

  // Search lectures while the student types.
  $("fTitle").addEventListener("input", showLectures);
}

// ---------- Upload Form ----------
function initUploadSelects() {
  fill($("uDept"), getDepartmentOptions(), "Choose Department");
  fill($("uSubject"), [], "Choose Subject");

  lock($("uSubject"), true);
}

function setupUpload() {
  const form = $("uploadForm");
  const message = $("uMsg");

  initUploadSelects();

  $("uDept").addEventListener("change", () => {
    const dept = $("uDept").value;

    fill($("uSubject"), getSubjectOptions(dept), "Choose Subject");

    lock($("uSubject"), !dept);
  });

  $("toggleUpload").addEventListener("click", () => {
    form.hidden = !form.hidden;
  });

  $("cancelUpload").addEventListener("click", () => {
    form.reset();
    initUploadSelects();

    form.hidden = true;
    message.textContent = "";
    message.className = "form-msg";
  });

  form.addEventListener("submit", event => {
    event.preventDefault();

    const dept = $("uDept").value;
    const subjectName = $("uSubject").value;
    const title = $("uTitle").value.trim();
    const year = Number($("uYear").value);
    const file = $("uFile").files[0];

    message.textContent = "";
    message.className = "form-msg";

    if (!dept || !subjectName || !title || !year || !file) {
      message.textContent = "Please fill in all fields.";
      message.classList.add("error");
      return;
    }

    if (!Number.isInteger(year) || year < 2000 || year > 2100) {
      message.textContent = "Please enter a valid year.";
      message.classList.add("error");
      return;
    }

    if (!/\.(pdf|ppt|pptx)$/i.test(file.name)) {
      message.textContent = "Only PDF, PPT and PPTX files are allowed.";
      message.classList.add("error");
      return;
    }

    if (file.size > 20 * 1024 * 1024) {
      message.textContent = "Maximum file size is 20 MB.";
      message.classList.add("error");
      return;
    }

    // Demo upload: real API integration will be added later.
    lectures.push({
      id: Date.now(),
      dept,
      subjectName,
      title,
      year,
      fileName: file.name,
      fileUrl: URL.createObjectURL(file),
      status: "pending"
    });

    message.textContent =
      "Demo only: nothing was uploaded. Uploads will work once the backend is connected.";

    message.classList.add("ok");

    form.reset();
    initUploadSelects();
  });
}

// ---------- Initialize ----------
function initLectures() {
  initFilters();
  setupFilters();
  setupUpload();
  showLectures();
}

document.addEventListener("DOMContentLoaded", initLectures);
