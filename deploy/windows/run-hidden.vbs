' Runs a program without a console window (scheduled tasks every minute
' would otherwise flash a window). Usage: wscript run-hidden.vbs <exe> <args...>
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
command = ""
For i = 0 To WScript.Arguments.Count - 1
    command = command & " """ & WScript.Arguments(i) & """"
Next
' Work in the application folder (the folder of "artisan", the 2nd argument).
If WScript.Arguments.Count > 1 Then shell.CurrentDirectory = fso.GetParentFolderName(WScript.Arguments(1))
shell.Run Trim(command), 0, True
