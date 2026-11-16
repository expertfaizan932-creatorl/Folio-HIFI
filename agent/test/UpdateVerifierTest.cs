using System;
using System.IO;
using System.Text;
using WorkFolio.Agent;

/// <summary>
/// Checks the update integrity gate. A download that does not match the
/// published SHA-256 must be refused, because this is the one place the agent
/// replaces its own executable.
/// </summary>
internal static class UpdateVerifierTest
{
    private static int _pass;
    private static int _fail;

    private static void Check(string label, object got, object want)
    {
        bool ok = Equals(got, want);
        if (ok) _pass++; else _fail++;
        Console.WriteLine("  " + (ok ? "PASS" : "FAIL") + "  " + label);
        if (!ok) Console.WriteLine("         got:  " + got + Environment.NewLine + "         want: " + want);
    }

    private static void CheckTrue(string label, bool ok)
    {
        Check(label, ok, true);
    }

    private static string WriteTemp(byte[] data)
    {
        string path = Path.Combine(Path.GetTempPath(), "wf_verify_" + Guid.NewGuid().ToString("N") + ".bin");
        File.WriteAllBytes(path, data);
        return path;
    }

    public static int Run()
    {
        _pass = 0;
        _fail = 0;
        Console.WriteLine();
        Console.WriteLine("--- update integrity ---");

        Console.WriteLine("== 1. known SHA-256 vectors ==");
        string empty = WriteTemp(new byte[0]);
        string emptyHash = UpdateVerifier.Sha256(empty);
        Check("sha256 of empty file", emptyHash, "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855");

        string abc = WriteTemp(Encoding.ASCII.GetBytes("abc"));
        Check("sha256 of 'abc'", UpdateVerifier.Sha256(abc), "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad");

        Console.WriteLine();
        Console.WriteLine("== 2. a matching hash passes, case-insensitively ==");
        string detail;
        CheckTrue("lowercase match", UpdateVerifier.VerifySha256(abc, "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad", out detail));
        CheckTrue("uppercase match", UpdateVerifier.VerifySha256(abc, "BA7816BF8F01CFEA414140DE5DAE2223B00361A396177A9CB410FF61F20015AD", out detail));
        CheckTrue("surrounding spaces ignored", UpdateVerifier.VerifySha256(abc, "  ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad  ", out detail));

        Console.WriteLine();
        Console.WriteLine("== 3. a wrong hash is refused with a reason ==");
        bool ok = UpdateVerifier.VerifySha256(abc, "0000000000000000000000000000000000000000000000000000000000000000", out detail);
        Check("mismatch rejected", ok, false);
        CheckTrue("and it explains why", detail != null && detail.IndexOf("mismatch", StringComparison.OrdinalIgnoreCase) >= 0);

        Console.WriteLine();
        Console.WriteLine("== 4. a missing hash does not block, but is called out ==");
        CheckTrue("no hash still passes", UpdateVerifier.VerifySha256(abc, "", out detail));
        CheckTrue("but it says unverified", detail != null && detail.Contains("without verification"));

        Console.WriteLine();
        Console.WriteLine("== 5. the signature hook is off until a cert exists ==");
        bool saved = UpdateVerifier.RequireSignature;
        UpdateVerifier.RequireSignature = false;
        CheckTrue("unsigned accepted while not required", UpdateVerifier.VerifySignature(abc, out detail));
        UpdateVerifier.RequireSignature = true;
        Check("required-but-unavailable fails closed", UpdateVerifier.VerifySignature(abc, out detail), false);
        UpdateVerifier.RequireSignature = saved;

        Console.WriteLine();
        Console.WriteLine("== 6. a missing file yields no hash ==");
        Check("missing file -> null", UpdateVerifier.Sha256(Path.Combine(Path.GetTempPath(), "wf_nope_" + Guid.NewGuid().ToString("N"))), null);

        try { File.Delete(empty); File.Delete(abc); } catch (Exception) { }

        Console.WriteLine();
        Console.WriteLine((_fail == 0 ? "ALL PASS" : "FAILURES") + ": " + _pass + " passed, " + _fail + " failed");
        return _fail;
    }
}
