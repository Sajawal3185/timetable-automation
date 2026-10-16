
# Basic Python Program

print("===== Simple Calculator =====")

num1 = float(input("Enter first number: "))
num2 = float(input("Enter second number: "))

print("Addition =", num1 + num2)
print("Subtraction =", num1 - num2)
print("Multiplication =", num1 * num2)

if num2 != 0:
    print("Division =", num1 / num2)
else:
    print("Cannot divide by zero")

if num1 > num2:
    print("First number is greater")
elif num1 < num2:
    print("Second number       is greater")
else:
    print("Both numbers are equal")

print("Program finished!")
