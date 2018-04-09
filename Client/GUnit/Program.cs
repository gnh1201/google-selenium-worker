using System;
using System.Collections;
using System.Collections.Specialized;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Newtonsoft.Json.Linq;

namespace GUnit
{
    class Program
    {
        private static string baseRemoteUrl = "http://127.0.0.1:3000";
        private static string worksListUrl = baseRemoteUrl + "receiver/works";
        private static string worksSetStatusUrl = baseRemoteUrl + "receiver/setstatus";
        private static int checkIntervalSeconds = 1;

        static void Main(string[] args)
        {
            Worker worker = new Worker();
            bool nextWork = true;
            bool waitWork = false;
            string afterWorkCountry = "";

            while (nextWork) {
                string response = worker.RequestByHttpGet(worksListUrl);
                JObject jsonObj = JObject.Parse(response);

                JToken worksList = jsonObj["works"];
                if (worksList.Count() > 0)
                {
                    waitWork = false;
                    Console.WriteLine(jsonObj.ToString());

                    foreach (JToken x in worksList)
                    {
                        string workKeyword = x["work_keyword"].ToString();
                        string instanceId = x["instance_id"].ToString();
                        int workStop = Int32.Parse(x["work_stop"].ToString());
                        string workCountry = x["work_country"].ToString();

                        // Reload on works
                        bool workReload = false;
                        if(!workCountry.Equals(afterWorkCountry))
                        {
                            workReload = true;
                        }

                        // Google Search
                        if (worker.SearchGoogle(workKeyword, instanceId, workStop, 10, workCountry, workReload))
                        {
                            // Change status (0 -> 1)
                            NameValueCollection nvc = new NameValueCollection();
                            nvc.Add("instance_id", x["instance_id"].ToString());
                            nvc.Add("status", "1");
                            string outResponse = worker.RequestByHttpGet(worksSetStatusUrl, nvc);
                            Console.WriteLine(outResponse);
                        }

                        afterWorkCountry = workCountry;
                    }
                } else
                {
                    if(waitWork == false) {
                        Console.WriteLine(jsonObj.ToString());
                        Console.WriteLine("작업을 기다리고 있습니다... " + checkIntervalSeconds + "초마다 확인합니다.");
                        waitWork = true;
                    }

                    worker.WaitSeconds(checkIntervalSeconds);
                }
            }
        }
    }
}
