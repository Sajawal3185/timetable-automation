const resources = [

  /* --- IDEs, editors and compilers --- */
  {
    name: "IntelliJ IDEA Community",
    category: "IDE",
    courses: ["Object Oriented Programming", "Data Structures", "Algorithms", "Software Construction and Development"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free and powerful IDE for Java and Kotlin development.",
    link: "https://www.jetbrains.com/idea/download/",
    steps: ["Download the Community edition.", "Run the installer and follow the instructions.", "Create a new project and select your JDK."]
  },
  {
    name: "Eclipse IDE",
    category: "IDE",
    courses: ["Object Oriented Programming", "Software Design and Architecture", "Software Construction and Development"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free Java IDE widely used in university courses.",
    link: "https://www.eclipse.org/downloads/",
    steps: ["Download the Eclipse Installer.", "Choose Eclipse IDE for Java Developers.", "Select a workspace folder and start coding."]
  },
  {
    name: "NetBeans",
    category: "IDE",
    courses: ["Object Oriented Programming", "Web Engineering", "Database Systems"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free IDE for Java desktop and web applications.",
    link: "https://netbeans.apache.org/front/main/download/",
    steps: ["Install a compatible JDK first.", "Download and install NetBeans.", "Create a new Java project."]
  },
  {
    name: "PyCharm Community",
    category: "IDE",
    courses: ["Programming Fundamentals", "Artificial Intelligence", "Machine Learning", "Data Science"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free IDE made for writing and debugging Python code.",
    link: "https://www.jetbrains.com/pycharm/download/",
    steps: ["Download the Community edition.", "Run the installer and follow the instructions.", "Create a project and choose a Python interpreter."]
  },
  {
    name: "Visual Studio Community",
    category: "IDE",
    courses: ["Object Oriented Programming", "Software Construction and Development", "Web Engineering", "Game Development"],
    os: ["Windows"],
    description: "A full free IDE for C#, C++ and .NET development.",
    link: "https://visualstudio.microsoft.com/vs/community/",
    steps: ["Download the Visual Studio installer.", "Select the workloads you need (for example .NET or C++).", "Install and sign in with a Microsoft account."]
  },
  {
    name: "Code::Blocks",
    category: "IDE",
    courses: ["Programming Fundamentals", "Object Oriented Programming", "Data Structures", "Algorithms"],
    os: ["Windows", "macOS", "Linux"],
    description: "A simple free C and C++ IDE for beginners.",
    link: "https://www.codeblocks.org/downloads/",
    steps: ["Download the version that includes MinGW (Windows).", "Run the installer and follow the instructions.", "Create a console project and run it."]
  },
  {
    name: "MSYS2 (MinGW-w64)",
    category: "Compiler",
    courses: ["Programming Fundamentals", "Data Structures", "Compiler Construction", "Operating Systems"],
    os: ["Windows"],
    description: "Installs the GCC C and C++ compiler on Windows.",
    link: "https://www.msys2.org/",
    steps: ["Download and install MSYS2.", "Open the MSYS2 terminal and install the GCC toolchain with pacman.", "Run gcc --version to check."]
  },
  {
    name: "Android Studio",
    category: "IDE",
    courses: ["Mobile Application Development", "Software Construction and Development", "Human Computer Interaction"],
    os: ["Windows", "macOS", "Linux"],
    description: "The official free IDE for building Android apps.",
    link: "https://developer.android.com/studio",
    steps: ["Download Android Studio.", "Install it and let the setup wizard download the SDK.", "Create a project and run it on the emulator."]
  },
  {
    name: "Flutter",
    category: "Framework",
    courses: ["Mobile Application Development", "Human Computer Interaction"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free toolkit to build mobile apps from a single codebase.",
    link: "https://docs.flutter.dev/get-started/install",
    steps: ["Download the Flutter SDK for your OS.", "Add Flutter to your PATH.", "Run flutter doctor to check your setup."]
  },

  /* --- Web development --- */
  {
    name: "Apache Maven",
    category: "Build Tool",
    courses: ["Software Construction and Development", "Web Engineering", "Software Quality Assurance"],
    os: ["Windows", "macOS", "Linux"],
    description: "Builds Java projects and manages their libraries.",
    link: "https://maven.apache.org/download.cgi",
    steps: ["Install a JDK first.", "Download and extract Maven, then add its bin folder to PATH.", "Run mvn -v in the terminal to check."]
  },
  {
    name: "Apache Tomcat",
    category: "Web Server",
    courses: ["Web Engineering", "Software Construction and Development"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free server for running Java web applications.",
    link: "https://tomcat.apache.org/download-10.cgi",
    steps: ["Install a JDK first.", "Download and extract Tomcat.", "Run the startup script and open localhost:8080."]
  },
  {
    name: "PHP",
    category: "Language",
    courses: ["Web Engineering", "Database Systems"],
    os: ["Windows", "macOS", "Linux"],
    description: "A popular language for server-side web development.",
    link: "https://www.php.net/downloads",
    steps: ["Download PHP for your OS.", "Install it or extract it and add it to PATH.", "Run php -v in the terminal to check."]
  },
  {
    name: "XAMPP",
    category: "Web Server",
    courses: ["Web Engineering", "Database Systems"],
    os: ["Windows", "macOS", "Linux"],
    description: "Installs Apache, MySQL and PHP together for local web development.",
    link: "https://www.apachefriends.org/download.html",
    steps: ["Download XAMPP for your OS.", "Run the installer and follow the instructions.", "Start Apache and MySQL from the control panel."]
  },
  {
    name: ".NET SDK",
    category: "Runtime",
    courses: ["Object Oriented Programming", "Web Engineering", "Software Construction and Development"],
    os: ["Windows", "macOS", "Linux"],
    description: "Tools for building C# apps, websites and APIs.",
    link: "https://dotnet.microsoft.com/download",
    steps: ["Download the latest LTS SDK.", "Run the installer.", "Run dotnet --version in the terminal to check."]
  },
  {
    name: "React",
    category: "Framework",
    courses: ["Web Engineering", "Human Computer Interaction"],
    os: ["Windows", "macOS", "Linux"],
    description: "A JavaScript library for building user interfaces.",
    link: "https://react.dev/learn/installation",
    steps: ["Install Node.js first.", "Create a project using Vite: npm create vite@latest.", "Run npm install and npm run dev."]
  },

  /* --- Databases --- */
  {
    name: "PostgreSQL",
    category: "Database",
    courses: ["Database Systems", "Web Engineering", "Data Science"],
    os: ["Windows", "macOS", "Linux"],
    description: "A powerful free open-source relational database.",
    link: "https://www.postgresql.org/download/",
    steps: ["Download the installer for your OS.", "Set a password for the postgres user during setup.", "Open pgAdmin or psql to connect."]
  },
  {
    name: "MongoDB Community",
    category: "Database",
    courses: ["Database Systems", "Web Engineering", "Big Data Analytics"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free NoSQL database that stores data as documents.",
    link: "https://www.mongodb.com/try/download/community",
    steps: ["Download MongoDB Community Server.", "Run the installer and follow the instructions.", "Connect using MongoDB Compass or mongosh."]
  },
  {
    name: "SQLite",
    category: "Database",
    courses: ["Database Systems", "Mobile Application Development", "Programming Fundamentals"],
    os: ["Windows", "macOS", "Linux"],
    description: "A small file-based database that needs no server.",
    link: "https://www.sqlite.org/download.html",
    steps: ["Download the command-line tools for your OS.", "Extract them and add the folder to PATH.", "Run sqlite3 in the terminal to check."]
  },
  {
    name: "DBeaver Community",
    category: "Database",
    courses: ["Database Systems", "Data Mining", "Big Data Analytics"],
    os: ["Windows", "macOS", "Linux"],
    description: "One free tool to connect to and query many databases.",
    link: "https://dbeaver.io/download/",
    steps: ["Download the Community edition.", "Install it and open DBeaver.", "Create a new connection to your database."]
  },
  {
    name: "Oracle Database XE",
    category: "Database",
    courses: ["Database Systems", "Data Mining"],
    os: ["Windows", "Linux"],
    description: "A free edition of Oracle Database for learning SQL and PL/SQL.",
    link: "https://www.oracle.com/database/technologies/xe-downloads.html",
    steps: ["Download the version for your OS.", "Run the installer and set an admin password.", "Connect using SQL Developer or SQL*Plus."]
  },

  /* --- Operating systems, virtualization and cloud --- */
  {
    name: "Docker Desktop",
    category: "DevOps",
    courses: ["Cloud Computing", "DevOps", "Web Engineering", "Software Construction and Development"],
    os: ["Windows", "macOS", "Linux"],
    description: "Runs apps in containers so they work the same on every computer.",
    link: "https://www.docker.com/products/docker-desktop/",
    steps: ["Download Docker Desktop for your OS.", "Install it and restart if asked.", "Run docker --version in the terminal to check."]
  },
  {
    name: "VirtualBox",
    category: "Virtualization",
    courses: ["Operating Systems", "Computer Networks", "Information Security", "Cloud Computing"],
    os: ["Windows", "macOS", "Linux"],
    description: "Runs other operating systems inside your computer.",
    link: "https://www.virtualbox.org/wiki/Downloads",
    steps: ["Download VirtualBox for your OS.", "Install it and follow the instructions.", "Create a new virtual machine with an ISO file."]
  },
  {
    name: "Ubuntu",
    category: "Operating System",
    courses: ["Operating Systems", "Computer Networks", "Cloud Computing", "DevOps"],
    os: ["Linux"],
    description: "A beginner-friendly free Linux operating system.",
    link: "https://ubuntu.com/download/desktop",
    steps: ["Download the Ubuntu ISO file.", "Install it in VirtualBox or on a USB drive.", "Open the terminal and practice Linux commands."]
  },
  {
    name: "WSL (Windows Subsystem for Linux)",
    category: "Operating System",
    courses: ["Operating Systems", "DevOps", "Web Engineering"],
    os: ["Windows"],
    description: "Runs Linux inside Windows without a virtual machine.",
    link: "https://learn.microsoft.com/en-us/windows/wsl/install",
    steps: ["Open PowerShell as administrator.", "Run wsl --install.", "Restart your computer and set up your Linux username."]
  },
  {
    name: "Jenkins",
    category: "DevOps",
    courses: ["DevOps", "Software Quality Assurance", "Cloud Computing"],
    os: ["Windows", "macOS", "Linux"],
    description: "Automatically builds and tests your code when it changes.",
    link: "https://www.jenkins.io/download/",
    steps: ["Install a JDK first.", "Download the installer for your OS.", "Open localhost:8080 and finish the setup."]
  },

  /* --- Networks and security --- */
  {
    name: "Wireshark",
    category: "Networking",
    courses: ["Computer Networks", "Information Security"],
    os: ["Windows", "macOS", "Linux"],
    description: "Captures and analyzes network traffic.",
    link: "https://www.wireshark.org/download.html",
    steps: ["Download Wireshark for your OS.", "Install it with the packet capture driver.", "Choose a network interface and start capturing."]
  },
  {
    name: "Cisco Packet Tracer",
    category: "Networking",
    courses: ["Computer Networks", "Information Security"],
    os: ["Windows", "macOS", "Linux"],
    description: "Simulates networks with routers and switches.",
    link: "https://www.netacad.com/",
    steps: ["Create a free Cisco Networking Academy account.", "Enroll in the free Packet Tracer course and download it.", "Install it and build a network."]
  },
  {
    name: "Nmap",
    category: "Security",
    courses: ["Computer Networks", "Information Security"],
    os: ["Windows", "macOS", "Linux"],
    description: "Scans networks to find devices and open ports.",
    link: "https://nmap.org/download.html",
    steps: ["Download Nmap for your OS.", "Run the installer.", "Run nmap --version in the terminal to check."]
  },
  {
    name: "Kali Linux",
    category: "Security",
    courses: ["Information Security", "Computer Networks"],
    os: ["Linux"],
    description: "A Linux system with built-in tools for security testing.",
    link: "https://www.kali.org/get-kali/",
    steps: ["Download the virtual machine image.", "Open it in VirtualBox.", "Use it only on systems you are allowed to test."]
  },
  {
    name: "Burp Suite Community",
    category: "Security",
    courses: ["Information Security", "Web Engineering"],
    os: ["Windows", "macOS", "Linux"],
    description: "Tests websites for security problems.",
    link: "https://portswigger.net/burp/communitydownload",
    steps: ["Download the Community edition.", "Install it and open Burp Suite.", "Set your browser to use the Burp proxy."]
  },
  {
    name: "OWASP ZAP",
    category: "Security",
    courses: ["Information Security", "Software Quality Assurance", "Web Engineering"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free scanner that finds security problems in web apps.",
    link: "https://www.zaproxy.org/download/",
    steps: ["Install a JDK if needed.", "Download and install ZAP.", "Enter a test website URL and run an automated scan."]
  },

  /* --- Modeling, design and project management --- */
  {
    name: "draw.io (diagrams.net)",
    category: "Modeling",
    courses: ["Software Engineering", "Software Requirements Engineering", "Software Design and Architecture", "Database Systems", "Final Year Project"],
    os: ["Windows", "macOS", "Linux"],
    description: "Draws UML, ER, flow and network diagrams for free.",
    link: "https://www.drawio.com/",
    steps: ["Open draw.io in your browser or download the desktop app.", "Choose where to save your diagrams.", "Pick a template or start with a blank diagram."]
  },
  {
    name: "PlantUML",
    category: "Modeling",
    courses: ["Software Engineering", "Software Requirements Engineering", "Software Design and Architecture"],
    os: ["Windows", "macOS", "Linux"],
    description: "Creates UML diagrams by writing simple text.",
    link: "https://plantuml.com/download",
    steps: ["Install Java first.", "Download plantuml.jar, or use the VS Code extension.", "Write your diagram text and generate an image."]
  },
  {
    name: "Visual Paradigm Community",
    category: "Modeling",
    courses: ["Software Engineering", "Software Requirements Engineering", "Software Design and Architecture", "Human Computer Interaction"],
    os: ["Windows", "macOS", "Linux"],
    description: "A UML modeling tool, free for non-commercial use.",
    link: "https://www.visual-paradigm.com/download/community.jsp",
    steps: ["Download the Community edition.", "Install it and request the free activation key.", "Create a new project and add UML diagrams."]
  },
  {
    name: "GitHub Desktop",
    category: "Version Control",
    courses: ["Software Engineering", "Software Construction and Development", "Web Engineering", "Final Year Project"],
    os: ["Windows", "macOS"],
    description: "A simple app to use Git and GitHub without commands.",
    link: "https://desktop.github.com/",
    steps: ["Download GitHub Desktop.", "Install it and sign in to your GitHub account.", "Clone a repository and make your first commit."]
  },
  {
    name: "Jira",
    category: "Project Management",
    courses: ["Software Project Management", "Software Engineering", "Final Year Project"],
    os: ["Windows", "macOS", "Linux"],
    description: "Plans and tracks tasks and sprints for team projects.",
    link: "https://www.atlassian.com/software/jira/free",
    steps: ["Sign up for the free plan in your browser.", "Create a Scrum or Kanban project.", "Invite your team and add tasks."]
  },

  /* --- Testing --- */
  {
    name: "Selenium",
    category: "Testing",
    courses: ["Software Quality Assurance", "Web Engineering"],
    os: ["Windows", "macOS", "Linux"],
    description: "Automates web browsers to test websites.",
    link: "https://www.selenium.dev/downloads/",
    steps: ["Install Python, Java or another supported language.", "Install the Selenium library, for example pip install selenium.", "Write a small script that opens a web page."]
  },
  {
    name: "JUnit 5",
    category: "Testing",
    courses: ["Software Quality Assurance", "Object Oriented Programming", "Software Construction and Development"],
    os: ["Windows", "macOS", "Linux"],
    description: "The standard library for writing unit tests in Java.",
    link: "https://junit.org/junit5/",
    steps: ["Use IntelliJ, Eclipse or Maven in your Java project.", "Add the JUnit 5 dependency.", "Write a test with @Test and run it."]
  },
  {
    name: "Apache JMeter",
    category: "Testing",
    courses: ["Software Quality Assurance", "Web Engineering"],
    os: ["Windows", "macOS", "Linux"],
    description: "Tests how fast a website or API works with many users.",
    link: "https://jmeter.apache.org/download_jmeter.cgi",
    steps: ["Install a JDK first.", "Download and extract JMeter.", "Run the jmeter script to open the app."]
  },

  /* --- Data science and AI --- */
  {
    name: "Jupyter Notebook",
    category: "Data Science",
    courses: ["Artificial Intelligence", "Machine Learning", "Data Science", "Data Mining", "Numerical Computing"],
    os: ["Windows", "macOS", "Linux"],
    description: "Write and run Python code with notes and charts in one page.",
    link: "https://jupyter.org/install",
    steps: ["Install Python first.", "Run pip install notebook.", "Run jupyter notebook to open it in the browser."]
  },
  {
    name: "Anaconda",
    category: "Data Science",
    courses: ["Artificial Intelligence", "Machine Learning", "Data Science", "Deep Learning"],
    os: ["Windows", "macOS", "Linux"],
    description: "Installs Python and popular data science tools together.",
    link: "https://www.anaconda.com/download",
    steps: ["Download the installer for your OS.", "Run it and follow the instructions.", "Open Anaconda Navigator or Anaconda Prompt."]
  },
  {
    name: "Google Colab",
    category: "Data Science",
    courses: ["Artificial Intelligence", "Machine Learning", "Deep Learning", "Natural Language Processing", "Computer Vision"],
    os: ["Windows", "macOS", "Linux"],
    description: "Runs Python notebooks online with a free GPU.",
    link: "https://colab.research.google.com/",
    steps: ["Sign in with your Google account.", "Create a new notebook.", "Choose Runtime, then Change runtime type, to use a GPU."]
  },
  {
    name: "TensorFlow",
    category: "AI Library",
    courses: ["Deep Learning", "Machine Learning", "Artificial Intelligence", "Computer Vision"],
    os: ["Windows", "macOS", "Linux"],
    description: "A Python library for building neural networks.",
    link: "https://www.tensorflow.org/install",
    steps: ["Install Python and create a virtual environment.", "Run pip install tensorflow.", "Run import tensorflow in Python to check."]
  },
  {
    name: "PyTorch",
    category: "AI Library",
    courses: ["Deep Learning", "Machine Learning", "Natural Language Processing", "Computer Vision"],
    os: ["Windows", "macOS", "Linux"],
    description: "A popular Python library for deep learning research.",
    link: "https://pytorch.org/get-started/locally/",
    steps: ["Open the link and choose your OS and package.", "Copy the install command shown.", "Run it in your terminal."]
  },
  {
    name: "scikit-learn",
    category: "AI Library",
    courses: ["Machine Learning", "Data Mining", "Data Science", "Artificial Intelligence"],
    os: ["Windows", "macOS", "Linux"],
    description: "A Python library with ready-made machine learning algorithms.",
    link: "https://scikit-learn.org/stable/install.html",
    steps: ["Install Python first.", "Run pip install scikit-learn.", "Run import sklearn in Python to check."]
  },
  {
    name: "Pandas",
    category: "AI Library",
    courses: ["Data Science", "Machine Learning", "Data Mining", "Big Data Analytics"],
    os: ["Windows", "macOS", "Linux"],
    description: "A Python library for cleaning and analyzing data tables.",
    link: "https://pandas.pydata.org/docs/getting_started/install.html",
    steps: ["Install Python first.", "Run pip install pandas.", "Run import pandas in Python to check."]
  },
  {
    name: "OpenCV",
    category: "AI Library",
    courses: ["Computer Vision", "Artificial Intelligence", "Deep Learning"],
    os: ["Windows", "macOS", "Linux"],
    description: "A library for image and video processing.",
    link: "https://opencv.org/releases/",
    steps: ["Install Python first.", "Run pip install opencv-python.", "Run import cv2 in Python to check."]
  },
  {
    name: "Weka",
    category: "Data Mining",
    courses: ["Data Mining", "Machine Learning", "Artificial Intelligence"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free app for data mining with a simple visual interface.",
    link: "https://ml.cms.waikato.ac.nz/weka/",
    steps: ["Download Weka for your OS.", "Install it and open the Weka GUI.", "Load a dataset and try a classifier."]
  },
  {
    name: "Orange",
    category: "Data Mining",
    courses: ["Data Mining", "Machine Learning", "Data Science"],
    os: ["Windows", "macOS", "Linux"],
    description: "Builds data analysis workflows by dragging and dropping blocks.",
    link: "https://orangedatamining.com/download/",
    steps: ["Download the installer for your OS.", "Install it and open Orange.", "Add widgets to the canvas and connect them."]
  },
  {
    name: "RStudio Desktop",
    category: "Data Science",
    courses: ["Data Science", "Data Mining", "Big Data Analytics", "Research Methodology"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free editor for the R language, used for statistics and charts.",
    link: "https://posit.co/download/rstudio-desktop/",
    steps: ["Install R first from the link on the same page.", "Download and install RStudio Desktop.", "Open RStudio and run a simple command."]
  },
  {
    name: "Apache Spark",
    category: "Big Data",
    courses: ["Big Data Analytics", "Parallel and Distributed Computing", "Data Science"],
    os: ["Windows", "macOS", "Linux"],
    description: "Processes very large datasets across many computers.",
    link: "https://spark.apache.org/downloads.html",
    steps: ["Install a JDK first.", "Download and extract Spark.", "Run the pyspark or spark-shell command to check."]
  },
  {
    name: "Ollama",
    category: "AI Tool",
    courses: ["Artificial Intelligence", "Natural Language Processing", "Deep Learning"],
    os: ["Windows", "macOS", "Linux"],
    description: "Runs open-source language models on your own computer.",
    link: "https://ollama.com/download",
    steps: ["Download Ollama for your OS.", "Install it and open a terminal.", "Run ollama run llama3.2 to start chatting."]
  },
  {
    name: "SWI-Prolog",
    category: "Language",
    courses: ["Artificial Intelligence", "Theory of Automata"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free Prolog system for logic programming and AI.",
    link: "https://www.swi-prolog.org/Download.html",
    steps: ["Download the stable version for your OS.", "Install it and follow the instructions.", "Open SWI-Prolog and load a .pl file."]
  },
  {
    name: "GNU Octave",
    category: "Math",
    courses: ["Numerical Computing", "Machine Learning", "Artificial Intelligence"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free MATLAB-like tool for math and numerical work.",
    link: "https://octave.org/download",
    steps: ["Download Octave for your OS.", "Install it and open the Octave GUI.", "Type a command like A = [1 2; 3 4] to test."]
  },

  /* --- Hardware, assembly and low-level --- */
  {
    name: "Logisim Evolution",
    category: "Simulator",
    courses: ["Digital Logic Design", "Computer Organization and Assembly Language"],
    os: ["Windows", "macOS", "Linux"],
    description: "Designs and tests digital logic circuits on screen.",
    link: "https://github.com/logisim-evolution/logisim-evolution/releases",
    steps: ["Install a JDK if you use the .jar version.", "Download the release for your OS.", "Open it and draw your first circuit."]
  },
  {
    name: "MARS MIPS Simulator",
    category: "Simulator",
    courses: ["Computer Organization and Assembly Language", "Operating Systems"],
    os: ["Windows", "macOS", "Linux"],
    description: "Runs and debugs MIPS assembly programs.",
    link: "http://courses.missouristate.edu/kenvollmar/mars/",
    steps: ["Install Java first.", "Download the Mars .jar file.", "Open it, write assembly code and click Assemble."]
  },
  {
    name: "NASM",
    category: "Assembler",
    courses: ["Computer Organization and Assembly Language", "Compiler Construction"],
    os: ["Windows", "macOS", "Linux"],
    description: "A free assembler for x86 assembly language.",
    link: "https://www.nasm.us/",
    steps: ["Download NASM for your OS.", "Install it and add it to PATH.", "Run nasm -v in the terminal to check."]
  },
  {
    name: "Arduino IDE",
    category: "IoT",
    courses: ["Internet of Things", "Computer Organization and Assembly Language"],
    os: ["Windows", "macOS", "Linux"],
    description: "Writes and uploads code to Arduino boards.",
    link: "https://www.arduino.cc/en/software",
    steps: ["Download the Arduino IDE.", "Install it and connect your board with a USB cable.", "Choose your board and port, then upload a sketch."]
  },

  /* --- Research and documents --- */
  {
    name: "Overleaf",
    category: "Documentation",
    courses: ["Research Methodology", "Final Year Project"],
    os: ["Windows", "macOS", "Linux"],
    description: "Writes LaTeX research papers and reports online for free.",
    link: "https://www.overleaf.com/",
    steps: ["Create a free account.", "Start a new project from a template.", "Edit the text and download the PDF."]
  },
  {
    name: "Zotero",
    category: "Documentation",
    courses: ["Research Methodology", "Final Year Project"],
    os: ["Windows", "macOS", "Linux"],
    description: "Saves research papers and creates citations and references.",
    link: "https://www.zotero.org/download/",
    steps: ["Download Zotero for your OS.", "Install it and the browser connector.", "Save papers while browsing and insert citations in Word."]
  }
];

const searchInput = document.getElementById("search");
const courseSelect = document.getElementById("course");
const categorySelect = document.getElementById("category");
const osSelect = document.getElementById("os");
const resourceList = document.getElementById("list");
const count = document.getElementById("count");
const toolsPerPage = 12;
let currentPage = 1;

function populateSelect(select, values, label) {
  const defaultOption = document.createElement("option");
  defaultOption.value = "";
  defaultOption.textContent = `All ${label}`;
  select.append(defaultOption);

  [...new Set(values)]
    .sort((first, second) => first.localeCompare(second))
    .forEach((value) => {
      const option = document.createElement("option");
      option.value = value;
      option.textContent = value;
      select.append(option);
    });
}

function createResourceCard(resource) {
  const card = document.createElement("article");
  card.className = "card";

  const title = document.createElement("h3");
  title.textContent = resource.name;
  card.append(title);

  const category = document.createElement("p");
  category.className = "small";
  category.textContent = resource.category;
  card.append(category);

  const description = document.createElement("p");
  description.textContent = resource.description;
  card.append(description);

  const courses = document.createElement("p");
  courses.className = "small";
  courses.textContent = `Courses: ${resource.courses.join(", ")}`;
  card.append(courses);

  const operatingSystems = document.createElement("div");
  resource.os.forEach((operatingSystem) => {
    const label = document.createElement("span");
    label.className = "os-label";
    label.textContent = operatingSystem;
    operatingSystems.append(label);
  });
  card.append(operatingSystems);

  const details = document.createElement("details");
  const summary = document.createElement("summary");
  summary.textContent = "Installation steps";
  details.append(summary);

  const steps = document.createElement("ol");
  resource.steps.forEach((step) => {
    const item = document.createElement("li");
    item.textContent = step;
    steps.append(item);
  });
  details.append(steps);
  card.append(details);

  const link = document.createElement("a");
  link.className = "button";
  link.href = resource.link;
  link.target = "_blank";
  link.rel = "noopener noreferrer";
  link.textContent = "Download / Visit";
  card.append(link);

  return card;
}

function renderResources() {
  const searchTerm = searchInput.value.trim().toLocaleLowerCase();
  const selectedCourse = courseSelect.value;
  const selectedCategory = categorySelect.value;
  const selectedOs = osSelect.value;

  const filteredResources = resources.filter((resource) => {
    const searchableText = [
      resource.name,
      resource.category,
      resource.description,
      ...resource.courses,
      ...resource.os
    ].join(" ").toLocaleLowerCase();

    return searchableText.includes(searchTerm)
      && (!selectedCourse || resource.courses.includes(selectedCourse))
      && (!selectedCategory || resource.category === selectedCategory)
      && (!selectedOs || resource.os.includes(selectedOs));
  });

  const totalPages = Math.ceil(filteredResources.length / toolsPerPage);
  currentPage = Math.min(currentPage, Math.max(1, totalPages));

  const startIndex = (currentPage - 1) * toolsPerPage;
  const pageResources = filteredResources.slice(startIndex, startIndex + toolsPerPage);

  resourceList.replaceChildren();
  count.textContent = filteredResources.length === 1
    ? "1 tool"
    : `${filteredResources.length} tools`;

  if (filteredResources.length === 0) {
    const message = document.createElement("p");
    message.className = "empty";
    message.textContent = "No tools match your filters.";
    resourceList.append(message);
  } else {
    pageResources.forEach((resource) => {
      resourceList.append(createResourceCard(resource));
    });
  }

  renderPagination(totalPages);
}

function renderPagination(totalPages) {
  let pagination = document.getElementById("pagination");

  if (!pagination) {
    pagination = document.createElement("div");
    pagination.id = "pagination";
    pagination.className = "pagination";
    resourceList.after(pagination);
  }

  pagination.replaceChildren();

  if (totalPages <= 1) {
    return;
  }

  const previousButton = document.createElement("button");
  previousButton.type = "button";
  previousButton.textContent = "Previous";
  previousButton.disabled = currentPage === 1;
  previousButton.addEventListener("click", () => {
    currentPage -= 1;
    renderResources();
  });
  pagination.append(previousButton);

  const pageInfo = document.createElement("span");
  pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
  pagination.append(pageInfo);

  const nextButton = document.createElement("button");
  nextButton.type = "button";
  nextButton.textContent = "Next";
  nextButton.disabled = currentPage === totalPages;
  nextButton.addEventListener("click", () => {
    currentPage += 1;
    renderResources();
  });
  pagination.append(nextButton);
}

populateSelect(courseSelect, resources.flatMap((resource) => resource.courses), "courses");
populateSelect(categorySelect, resources.map((resource) => resource.category), "categories");
populateSelect(osSelect, resources.flatMap((resource) => resource.os), "operating systems");

searchInput.addEventListener("input", () => {
  currentPage = 1;
  renderResources();
});

courseSelect.addEventListener("change", () => {
  currentPage = 1;
  renderResources();
});

categorySelect.addEventListener("change", () => {
  currentPage = 1;
  renderResources();
});

osSelect.addEventListener("change", () => {
  currentPage = 1;
  renderResources();
});

renderResources();