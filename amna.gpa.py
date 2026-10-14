def calculate_gpa():
    # Standard 4.0 grade scale mapping
    grade_scale = {
        'A': 4.0, 'A-': 3.7,
        'B+': 3.3, 'B': 3.0, 'B-': 2.7,
        'C+': 2.3, 'C': 2.0, 'C-': 1.7,
        'D+': 1.3, 'D': 1.0, 'F': 0.0
    }
    
    total_quality_points = 0.0
    total_credits = 0.0
    
    print("=== GPA Calculator ===")
    print("Type 'done' for the course name when you are finished.\n")
    
    while True:
        course_name = input("Enter course name (or 'done'): ").strip()
        if course_name.lower() == 'done':
            break
            
        grade = input(f"Enter grade for {course_name} (e.g., A, B+, C): ").strip().upper()
        if grade not in grade_scale:
            print("Invalid grade entered. Please use standard letter grades (A, B+, C, etc.).\n")
            continue
            
        try:
            credits = float(input(f"Enter credit hours for {course_name}: "))
            if credits < 0:
                print("Credit hours cannot be negative.\n")
                continue
        except ValueError:
            print("Please enter a valid number for credit hours.\n")
            continue
            
        # Calculate quality points (grade points * credit hours)
        quality_points = grade_scale[grade] * credits
        total_quality_points += quality_points
        total_credits += credits
        print(f"Added: {grade} ({grade_scale[grade]}) for {credits} credits.\n")
        
    if total_credits == 0:
        print("\nNo courses entered.")
        return
        
    gpa = total_quality_points / total_credits
    print("\n-------------------------")
    print(f"Total Credits: {total_credits}")
    print(f"Total Quality Points: {total_quality_points:.2f}")
    print(f"Your Cumulative GPA: {gpa:.2f}")
    print("-------------------------")

if __name__ == "__main__":
    calculate_gpa()