const $ = id => document.getElementById(id);
const thisYear = new Date().getFullYear();

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
  list.forEach(([code, name]) => {
    SUBJECTS.push({ dept, code, name });
  });
}

addSubjects("SE", [
  ["SEIT-111", "Application of Information and Communication Technology"],
  ["SEPF-121", "Programming Fundamentals"],
  ["SECA-117", "Calculus and Analytical Geometry"],
  ["SEDS-116", "Discrete Structures"],
  ["SEOO-122", "Object Oriented Programming"],
  ["SESE-123", "Software Engineering"],
  ["SEDA-225", "Data Structure and Algorithms"],
  ["SELA-201", "Linear Algebra"],
  ["SEOS-226", "Operating Systems"],
  ["SESD-242", "Software Design & Architecture"],
  ["SEFM-262", "Formal Methods in SE"],
  ["SEDB-227", "Database Systems"],
  ["SEBP-263", "Business Process Engineering"],
  ["SESR-241", "Software Requirements Engineering"],
  ["SEPS-303", "Probability & Statistics"],
  ["SESC-343", "Software Construction & Development"],
  ["SEAI-331", "Artificial Intelligence"],
  ["SEHC-364", "Human Computer Interaction"],
  ["SECN-228", "Computer Networks"],
  ["SEVP-365", "Visual Programming"],
  ["SEIS-432", "Information Security"],
  ["SESQ-344", "Software Quality Engineering"],
  ["SEWE-363", "Web Engineering"],
  ["SEMA-366", "Mobile App Development"],
  ["SESR-448", "Software Re-Engineering"],
  ["SESP-445", "Software Project Management"],
  ["SEOR-468", "Operation Research"],
  ["SEGD-468", "Game Development"],
  ["SEPI-468", "Software Process Improvement"],
  ["SEWE-467", "Web Engineering II"],
  ["SEAP-113", "Applied Physics"],
  ["SEFE-114", "Functional English"],
  ["IS-112", "Islamic Studies"],
  ["PS-118", "Ideology and Constitution of Pakistan"],
  ["SEEW-115", "Expository Writing"],
  ["SEIP-219", "Introduction to Psychology"],
  ["SETB-304", "Technical & Business Writing"],
  ["SEPP-422", "Professional Practices"],
  ["SEEP-421", "Entrepreneurship"],
  ["SESS-367", "Social Service"],
  ["SEAF-161", "Accounting Fundamentals"]
]);

addSubjects("CS", [
  ["CSIT-107", "Application of Info. & Comm. Tech."],
  ["ESFE-108", "Functional English"],
  ["SSIS-109", "Islamic Studies"],
  ["EEAP-110", "Applied Physics"],
  ["SSPS-111", "Pakistan Studies"],
  ["CSPF-112", "Programming Fundamentals"],
  ["MTPM-161", "Math-I"],
  ["MTCA-113", "Calculus and Analytical Geometry"],
  ["ESEW-114", "Expository Writing"],
  ["SSCE-115", "Civics and Community Engagement"],
  ["CSDT-116", "Discrete Structures"],
  ["CSOO-117", "Object Oriented Programming"],
  ["SSIP-118", "Ideology and Constitution of Pakistan"],
  ["MTPM-162", "Math-II"],
  ["CSDM-205", "Digital Marketing"],
  ["SSFQ-206", "Fehm-e-Quran-I"],
  ["CSDL-207", "Digital Logic Design"],
  ["CSDS-208", "Data Structures"],
  ["CSDB-210", "Database System"],
  ["MTLA-211", "Linear Algebra"],
  ["CSPP-212", "Professional Practices"],
  ["CSAI-213", "Artificial Intelligence"],
  ["CSSE-214", "Software Engineering"],
  ["CSCO-215", "Comp. Org. & Assembly Language"],
  ["CSWT-216", "Web-Technology"],
  ["MSTE-214", "Technology Enterpreneurship"],
  ["SSFQ-241", "Fehm-e-Quran-II"],
  ["CSCA-340", "Computer Architecture"],
  ["CSAA-341", "Analysis of Algorithms"],
  ["MTMV-342", "Multi Variable Calculus"],
  ["MTSP-343", "Statistics & Probability"],
  ["CSOS-344", "Operating System"],
  ["CSWE-345", "Web Engineering"],
  ["CSTA-347", "Theory of Automata"],
  ["CSCN-348", "Computer Networks"],
  ["CSNA-349", "Numerical Analysis"],
  ["CSAP-350", "Advanced Programming (VP)"],
  ["CSAD-351", "Adv. Database Management System"],
  ["ESTW-352", "Technical & Business Writing"],
  ["CSCG-420", "HCI & Computer Graphics"],
  ["CSCC-421", "Compiler Construction"],
  ["CSDC-422", "Parallel & Distributed Computing"],
  ["SEST-423", "Software Testing & Quality Assurance"],
  ["CSMA-424", "Mobile Application Development"],
  ["FLxx-426", "Foreign Language (Chinese / Japanese / French / Arabic / German)"],
  ["CSCD-427", "Cloud Computing"],
  ["CSIS-428", "Information Security"],
  ["MSFM-425", "Financial Management"]
]);
addSubjects("AI", [
  ["CSIT-107", "Application of Information & Communication Technology"],
  ["CSPF-121", "Programming Fundamentals"],
  ["ESFE-108", "Functional English"],
  ["CSDT-110", "Applied Physics"],
  ["EEAP-109", "Islamic Studies"],
  ["SSIS-111", "Discrete Structures"],
  ["MTPM-161", "Maths-I"],
  ["CSOO-122", "Object Oriented Programming"],
  ["MTCA-112", "Calculus and Analytical Geometry"],
  ["CSDL-123", "Digital Logic Design"],
  ["ESEW-113", "Expository Writing"],
  ["SSCE-114", "Civics & Community Engagement"],
  ["SSIP-115", "Ideology and Constitution of Pakistan"],
  ["MTPM-162", "Pre-Maths-II"],
  ["CSDS-224", "Data Structures"],
  ["CSPA-241", "Programming for Artificial Intelligence"],
  ["CSPP-216", "Professional Practices"],
  ["MTSP-201", "Statistics & Probability"],
  ["MTMC-202", "Multivariable Calculus"],
  ["CSCN-225", "Computer Networks"],
  ["CSDM-217", "Digital Marketing"],
  ["MTAS-263", "Advanced Statistics"],
  ["CSAA-226", "Analysis of Algorithms"],
  ["CSAI-227", "Artificial Intelligence"],
  ["CSDB-228", "Database Systems"],
  ["MTLA-203", "Linear Algebra"],
  ["CSNN-342", "Artificial Neural Network"],
  ["CSSE-329", "Software Engineering"],
  ["CSML-343", "Machine Learning"],
  ["CSKR-344", "Knowledge Representation & Reasoning"],
  ["CSCO-330", "Computer Organization & Assembly Language"],
  ["MSAF-376", "Accounting Fundamentals"],
  ["CSOS-331", "Operating System"],
  ["CSCV-345", "Computer Vision"],
  ["CSNL-364", "Natural Language Processing"],
  ["CSDM-365", "Data Mining"],
  ["ESTW-304", "Technical and Business Writing"],
  ["CSDC-446", "Parallel & Distributed Computing"],
  ["CSCG-466", "HCI & Computer Graphics"],
  ["CSTA-467", "Theory of Automata"],
  ["CSFS-468", "Fuzzy Systems"],
  ["CSRL-469", "Reinforcement Learning"],
  ["MSTE-418", "Technology Entrepreneurship"],
  ["CSIS-432", "Information Security"]
]);
  addSubjects("IT", [
  ["ITPF-121", "Programming Fundamentals"],
  ["ITIT-111", "Application to Information & Communication Technology"],
  ["IS-112", "Islamic Studies"],
  ["NSAP-103", "Applied Physics"],
  ["ESFE-104", "Functional English"],
  ["ITHR-176", "Human Resource Management"],
  ["ESEW-105", "Expository Writing"],
  ["ITOO-127", "Object Oriented Programming"],
  ["QRDS-106", "Discrete Structures"],
  ["ITSE-128", "Software Engineering"],
  ["QRCA-107", "Calculus & Analytical Geometry"],
  ["SSPS-108", "Pakistan Studies"],
  ["SSIP-209", "Introduction to Psychology"],
  ["ITDA-229", "Data Structures"],
  ["ITLA-218", "Linear Algebra"],
  ["ITES-277", "Enterprise System"],
  ["AHPP-210", "Professional Practices"],
  ["ITCN-230", "Computer Networks"],
  ["SSCP-211", "Ideology and Constitution of Pakistan"],
  ["ITDB-231", "Database Systems"],
  ["ITOS-232", "Operating Systems"],
  ["ITSR-278", "Software Requirement Engineering"],
  ["ITMV-219", "Multivariable Calculus"],
  ["MSEP-212", "Entrepreneurship"],
  ["SSCE-213", "Civics and Community Engagement"],
  ["ITSN-333", "System & Network Administration"],
  ["ITDM-334", "Database Administration & Management"],
  ["ITDL-335", "Digital Logic Design"],
  ["ITAO-336", "Analysis of Algorithm"],
  ["ITIS-337", "Information Security"],
  ["ITDK-379", "Digital Marketing"],
  ["ITTB-320", "Technical & Business Writing"],
  ["ITII-338", "IT Infrastructure"],
  ["ITAI-339", "Artificial Intelligence"],
  ["ITWT-340", "Web Technologies"],
  ["ITPS-321", "Probability & Statistics"],
  ["ITMA-380", "Mobile Application Development"],
  ["ITPM-481", "Software Project Management"],
  ["ITPC-441", "Parallel & Distributed Computing"],
  ["ITCO-442", "Computer Organization & Assembly Language"],
  ["ITVS-482", "Virtual Systems and Services"],
  ["ITCS-443", "Cyber Security"],
  ["ITDO-484", "Fundamentals of DevOps"],
  ["ITFL-486", "Foreign Language"]
]);

// ---------- Demo Records ----------
// Teacher names are sample data; real data will come from the API.
// Uploaded records are temporary until the backend is connected.

const lectures = [
  { id: 1, dept: "SE", subjectCode: "SEDA-225", teacher: "Sir Ali", title: "Trees and Graphs", year: 2024, fileName: "dsa-trees.pdf", fileUrl: "../lectures/dsa-trees.pdf", status: "approved" },
  { id: 2, dept: "SE", subjectCode: "SEDA-225", teacher: "Sir Ali", title: "Stacks and Queues", year: 2023, fileName: "dsa-stacks.pdf", fileUrl: "../lectures/dsa-stacks.pdf", status: "approved" },
  { id: 3, dept: "SE", subjectCode: "SEOS-226", teacher: "Ms Sara", title: "Process Scheduling", year: 2024, fileName: "os-scheduling.pdf", fileUrl: "../lectures/os-scheduling.pdf", status: "approved" }
];

// ---------- Dropdown Helpers ----------
function fill(select, items, placeholder) {
  select.replaceChildren();

  const first = new Option(placeholder, "");
  first.disabled = true;
  first.selected = true;
  select.add(first);

  items.forEach(item => select.add(new Option(item.label, String(item.value))));
  select.value = "";
}

function lock(control, locked) {
  control.disabled = locked;
  control.classList.toggle("locked", locked);
}

function getDepartmentOptions() {
  return DEPARTMENTS.map(d => ({ value: d.code, label: `${d.code} - ${d.name}` }));
}

function getSubjectOptions(dept) {
  return SUBJECTS
    .filter(s => s.dept === dept)
    .map(s => ({ value: s.code, label: `${s.code} - ${s.name}` }));
}

function getSubjectName(dept, code) {
  const subject = SUBJECTS.find(s => s.dept === dept && s.code === code);
  return subject ? subject.name : code;
}

// ---------- Teacher and Year Options ----------
// Teachers and years are taken from the existing approved lectures.

function approvedFor(dept, code) {
  return lectures.filter(l =>
    l.status === "approved" && l.dept === dept && l.subjectCode === code);
}

function getTeacherOptions(dept, code) {
  return [...new Set(approvedFor(dept, code).map(l => l.teacher))]
    .sort()
    .map(t => ({ value: t, label: t }));
}

function getYearOptions(dept, code, teacher) {
  return [...new Set(
    approvedFor(dept, code).filter(l => l.teacher === teacher).map(l => l.year)
  )]
    .sort((a, b) => b - a)
    .map(y => ({ value: y, label: String(y) }));
}

// ---------- Filter Initial State ----------
function initFilters() {
  fill($("fDept"), getDepartmentOptions(), "Choose Department");
  fill($("fSubject"), [], "Choose Subject");
  fill($("fTeacher"), [], "Choose Teacher");
  fill($("fYear"), [], "Choose Year");
  $("fTitle").value = "";

  lock($("fDept"), false);
  lock($("fSubject"), true);
  lock($("fTeacher"), true);
  lock($("fYear"), true);
  lock($("fTitle"), true);
}

// ---------- Display Lectures ----------
function showLectures() {
  const dept = $("fDept").value;
  const subjectCode = $("fSubject").value;
  const teacher = $("fTeacher").value;
  const year = $("fYear").value;
  const titleFilter = $("fTitle").value.trim().toLowerCase();

  const list = $("list");
  list.replaceChildren();

  // Display results only after all required filters are selected.
  if (!dept || !subjectCode || !teacher || !year) {
    $("hint").hidden = false;
    $("empty").hidden = true;
    return;
  }

  $("hint").hidden = true;

  const results = approvedFor(dept, subjectCode).filter(item =>
    item.teacher === teacher &&
    String(item.year) === year &&
    (!titleFilter || item.title.toLowerCase().includes(titleFilter))
  );

  results.forEach(item => {
    const card = document.createElement("article");
    card.className = "paper-card";

    const title = document.createElement("h3");
    title.textContent = item.title;

    const course = document.createElement("p");
    course.textContent = `${item.subjectCode} - ${getSubjectName(item.dept, item.subjectCode)}`;

    const teacherLine = document.createElement("p");
    teacherLine.textContent = `Teacher: ${item.teacher}`;

    const yearLine = document.createElement("p");
    yearLine.textContent = `Year: ${item.year}`;

    const fileName = document.createElement("p");
    fileName.textContent = item.fileName;

    const actions = document.createElement("div");
    actions.className = "actions";

    const view = document.createElement("a");
    view.href = item.fileUrl;
    view.target = "_blank";
    view.rel = "noopener noreferrer";
    view.className = "btn";
    view.textContent = "View Slides";

    const download = document.createElement("a");
    download.href = item.fileUrl;
    download.download = item.fileName;
    download.className = "btn solid";
    download.textContent = "Download";

    actions.append(view, download);
    card.append(title, course, teacherLine, yearLine, fileName, actions);
    list.append(card);
  });

  $("empty").hidden = results.length > 0;
}

// ---------- Filter Event Listeners ----------
function setupFilters() {
  $("fDept").addEventListener("change", () => {
    const dept = $("fDept").value;

    fill($("fSubject"), getSubjectOptions(dept), "Choose Subject");
    fill($("fTeacher"), [], "Choose Teacher");
    fill($("fYear"), [], "Choose Year");
    $("fTitle").value = "";

    lock($("fSubject"), !dept);
    lock($("fTeacher"), true);
    lock($("fYear"), true);
    lock($("fTitle"), true);

    showLectures();
  });

  $("fSubject").addEventListener("change", () => {
    const dept = $("fDept").value;
    const code = $("fSubject").value;

    fill($("fTeacher"), getTeacherOptions(dept, code), "Choose Teacher");
    fill($("fYear"), [], "Choose Year");
    $("fTitle").value = "";

    lock($("fTeacher"), !code);
    lock($("fYear"), true);
    lock($("fTitle"), true);

    showLectures();
  });

  $("fTeacher").addEventListener("change", () => {
    const teacher = $("fTeacher").value;

    fill($("fYear"), getYearOptions($("fDept").value, $("fSubject").value, teacher), "Choose Year");
    $("fTitle").value = "";

    lock($("fYear"), !teacher);
    lock($("fTitle"), true);

    showLectures();
  });

  $("fYear").addEventListener("change", () => {
    $("fTitle").value = "";
    lock($("fTitle"), !$("fYear").value);
    showLectures();
  });

  // Update lecture results whenever the user types in the title field.
  $("fTitle").addEventListener("input", showLectures);
}

// ---------- Upload Form (Demo Only) ----------
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
    const subjectCode = $("uSubject").value;
    const teacher = $("uTeacher").value.trim();
    const title = $("uTitle").value.trim();
    const year = Number($("uYear").value);
    const file = $("uFile").files[0];

    message.textContent = "";
    message.className = "form-msg";

    // Check that all required fields are filled.
    if (!dept || !subjectCode || !teacher || !title || !year || !file) {
      message.textContent = "Please fill in all fields.";
      message.classList.add("error");
      return;
    }

    // Validate the entered year.
    if (!Number.isInteger(year) || year < 2000 || year > 2100) {
      message.textContent = "Please enter a valid year.";
      message.classList.add("error");
      return;
    }

    // Allow only PDF, PPT, and PPTX files.
    if (!/\.(pdf|ppt|pptx)$/i.test(file.name)) {
      message.textContent = "Only PDF, PPT and PPTX files are allowed.";
      message.classList.add("error");
      return;
    }

    // Check that the file size does not exceed 20 MB.
    if (file.size > 20 * 1024 * 1024) {
      message.textContent = "Maximum file size is 20 MB.";
      message.classList.add("error");
      return;
    }

    // TODO (Backend Team):
    // Send the department, subject code, teacher, title, year, and file to the API using fetch POST and FormData.
    // The server should save the uploaded record with "pending" status and validate the file again.

    lectures.push({
      id: Date.now(),
      dept,
      subjectCode,
      teacher,
      title,
      year,
      fileName: file.name,
      fileUrl: URL.createObjectURL(file),
      status: "pending"
    });

    message.textContent = "Demo only: nothing was uploaded. Uploads will work once the backend is connected.";
    message.classList.add("ok");

    form.reset();
    initUploadSelects();
  });
}

// ---------- Initialize the Lectures Page ----------
function initLectures() {
  initFilters();
  setupFilters();
  setupUpload();
  showLectures();
}

document.addEventListener("DOMContentLoaded", initLectures);