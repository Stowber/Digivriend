Option Explicit

' 1. Argument (ID-code) ophalen
Dim objDoc, ret, ID
If WScript.Arguments.Count = 0 Then
    WScript.Echo "Geen ID-code meegegeven!"
    WScript.Quit 1
End If
ID = WScript.Arguments(0)

' 2. bPAC Document-object maken
Set objDoc = CreateObject("bpac.Document")

' 3. lbx-bestand openen
ret = objDoc.Open("C:\xampp\htdocs\Digivriend3\Klantcode.lbx")
If ret <> True Then
  WScript.Echo "Kon label-lbx niet openen!"
  WScript.Quit 1
End If

' 4. Tekstobject "IDCODE" vullen
objDoc.GetObject("IDCODE").Text = ID

' 5. Printen
objDoc.StartPrint "", 1  ' "" = default printer, 1 = copies
objDoc.PrintOut 1, 0     ' 1 = aantal labels
objDoc.EndPrint

objDoc.Close
Set objDoc = Nothing

If ret <> True Then
  WScript.Echo "Kon label-lbx niet openen!"
  WScript.Quit 1
End If

