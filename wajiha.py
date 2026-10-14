from collections import defaultdict

# Example data
courses = [
    {"name": "Mathematics", "teacher": "Aisha", "room": "Room 101", "day": "Monday", "time": "09:00-10:00"},
    {"name": "Physics", "teacher": "Ali", "room": "Room 102", "day": "Monday", "time": "09:00-10:00"},
    {"name": "Computer Science", "teacher": "Aisha", "room": "Lab 1", "day": "Tuesday", "time": "11:00-12:00"},
    {"name": "English", "teacher": "Sara", "room": "Room 103", "day": "Wednesday", "time": "10:00-11:00"},
    {"name": "Chemistry", "teacher": "Ali", "room": "Lab 2", "day": "Thursday", "time": "09:00-10:00"},
]

days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]
time_slots = ["09:00-10:00", "10:00-11:00", "11:00-12:00", "12:00-01:00"]

def generate_timetable(course_list):
    timetable = {day: {} for day in days}
    teacher_schedule = defaultdict(set)
    room_schedule = defaultdict(set)

    for course in course_list:
        slot = (course["day"], course["time"])
        teacher = course["teacher"]
        room = course["room"]

        # Check conflicts
        if slot in teacher_schedule[teacher]:
            raise ValueError(f"Conflict: {teacher} is already assigned at {course['day']} {course['time']}")
        if slot in room_schedule[room]:
            raise ValueError(f"Conflict: {room} is already booked at {course['day']} {course['time']}")

        teacher_schedule[teacher].add(slot)
        room_schedule[room].add(slot)

        timetable[course["day"]][course["time"]] = {
            "subject": course["name"],
            "teacher": teacher,
            "room": room
        }

    return timetable

def print_timetable(timetable):
    for day in days:
        print(f"\n{day}")
        print("-" * 40)
        for slot in time_slots:
            if slot in timetable[day]:
                entry = timetable[day][slot]
                print(f"{slot:>12} | {entry['subject']:<20} | {entry['teacher']:<12} | {entry['room']}")
            else:
                print(f"{slot:>12} | Free")

# Run
try:
    schedule = generate_timetable(courses)
    print_timetable(schedule)
except ValueError as e:
    print("Error:", e)