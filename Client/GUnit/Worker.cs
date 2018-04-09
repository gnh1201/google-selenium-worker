using System;
using System.Collections.Generic;
using System.Collections.ObjectModel;
using System.Collections.Specialized;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using OpenQA.Selenium;
using OpenQA.Selenium.Firefox;
using OpenQA.Selenium.Support.UI;

namespace GUnit
{
    class Worker
    {
        private IWebElement q;
        private string s;
        private IWebDriver driver = null;
        private FirefoxOptions ffOptions = null;
        private string baseUrl = "http://demo.bmcnet.kr/grank-remote";
        private string instanceId = "";
        private string afterKeyword = "";
        private bool robotProtection = true;
        private NameValueCollection countries = null;

        public Worker()
        {
            countries = new NameValueCollection();
      
            // country list
            countries.Add("kr", "ko-KR");
            countries.Add("co.kr", "ko-KR");
            countries.Add("com.hk", "zh-CN"); // use hong-kong in china
            countries.Add("jp", "ja-JP");
            countries.Add("de", "de-DE");
            countries.Add("ru", "ru-RU");
            countries.Add("us", "en-US");
            countries.Add("uk", "en-GB");
            countries.Add("fr", "fr-FR");
            countries.Add("com.sa", "ar-SA");
            countries.Add("com", "en-US");

            // predefined terms
            countries.Add("default_2c", "en");
            countries.Add("default_5c", "en-US");
            countries.Add("default_country", "us");
            countries.Add("default_domain", "com");
        }

        public void setRobotProtection(bool value)
        {
            robotProtection = value;
        }

        public bool SearchGoogle(string keyword, string token = null, int max = 20, int unit = 10, string country = "com", bool reload = false)
        {
            bool exists = false;
            string langDomain = countries["default_domain"];
            bool driverLoaded = (driver == null) ? false : true;

            // reload firefox process
            if (reload && driverLoaded)
            {
                driver.Quit();
            }

            string langFont = "";
            string langAccepts = "";
            string langSearch = "";
            foreach (string key in countries)
            {
                string name = key;
                string[] nameExps = key.Split('.');
                if (nameExps.Length > 1)
                {
                    name = nameExps.Last();
                }

                langFont = name;
                langAccepts = countries[key] + ", " + name;
                langSearch = name.ToUpper();

                if (key.EndsWith(country))
                {
                    if (countries["default_country"] == name)
                    {
                        langDomain = countries["default_domain"];
                    }
                } else
                {
                    langDomain = countries["default_domain"];
                }
            }

            if (langFont == String.Empty)
            {
                langFont = countries["default_2c"];
                langAccepts = countries["default_5c"] + ", " + countries["default_5c"];
                langSearch = countries["default_2c"].ToUpper();
                langDomain = countries["default_domain"];
            }

            if(reload || !driverLoaded) {
                ffOptions = new FirefoxOptions();
                ffOptions.SetPreference("geo.enabled", false);
                ffOptions.SetPreference("font.language.group", langFont);
                ffOptions.SetPreference("intl.accept_languages", langAccepts);
                ffOptions.SetPreference("browser.search.countryCode", langSearch);
                ffOptions.ToCapabilities();
                driver = new FirefoxDriver(ffOptions);
            }

            if (token == null)
            {
                instanceId = TextUtils.MakeRandomId(8);
            } else
            {
                instanceId = token;
            }

            driver.Url = "https://google." + langDomain;
            q = driver.FindElement(By.Name("q"));
            q.SendKeys(keyword);
            q.Submit();

            WebDriverWait wait = new WebDriverWait(driver, TimeSpan.FromSeconds(30));
            try
            {
                wait.Until((d) =>
                {
                    bool found = false;
                    string[] urlBlks = d.Url.Split('/');
                    if (urlBlks.Length > 3 && urlBlks[3].StartsWith("search"))
                    {
                        try
                        {
                            if (d.FindElement(By.CssSelector("h3.r")).Displayed)
                            {
                                found = true;
                                exists = true;
                            }
                        }
                        catch (NoSuchElementException)
                        {
                            // Nothing (found = false)
                        }
                    }
                    return found;
                }
                );
            }
            catch (WebDriverTimeoutException)
            {
                Console.WriteLine("검색결과 대기시간을 초과하였습니다.");
            }

            // save old keyword
            afterKeyword = keyword;

            sendPageSource();
            bypassRobotProtection();
            retrieveNextPage(2, max); // goto page 

            return exists;
        }

        // send page source
        private void sendPageSource()
        {
            // get page source
            s = driver.PageSource;
            Console.WriteLine("Content size: " + s.Length.ToString());

            byte[] sc = TextUtils.ZipStr(s);
            Console.WriteLine("Compressed Content size: " + sc.Length.ToString());

            NameValueCollection nvc = new NameValueCollection();
            nvc.Add("keyword", afterKeyword);
            nvc.Add("searchurl", driver.Url);
            sendBytesToReceiver(sc, nvc);
        }

        // access next page
        private void retrieveNextPage(int page, int max = 20, int unit = 10)
        {
            ReadOnlyCollection<IWebElement> pageNavElems = driver.FindElements(By.CssSelector("#nav a.fl"));
            foreach (IWebElement elem in pageNavElems)
            {
                string ariaLabel = elem.GetAttribute("aria-label");
                string compareLabel = "Page " + page.ToString();
                string pageLink = elem.GetAttribute("href");
                string pageStart = TextUtils.ParseQueryString(pageLink).Get("start");

                if (ariaLabel == compareLabel)
                {
                    elem.Click();

                    WebDriverWait wait = new WebDriverWait(driver, TimeSpan.FromSeconds(30));
                    try
                    {
                        wait.Until((d) =>
                        {
                            bool found = false;
                            string afterPageStart = TextUtils.ParseQueryString(driver.Url).Get("start");
                            if (pageStart == afterPageStart)
                            {
                                found = true;
                            }
                            return found;
                        });
                    }
                    catch (WebDriverTimeoutException)
                    {
                        Console.WriteLine("검색결과 대기시간을 초과하였습니다.");
                    }

                    // send page source to server
                    sendPageSource();

                    // find next page
                    if (page < ((int)(max / unit) + 1))
                    {
                        bypassRobotProtection();
                        retrieveNextPage(++page, max, unit);
                    }

                    break;
                }
            }
        }

        // Send compressed data to server
        private void sendBytesToReceiver(byte[] sc, NameValueCollection nvc = null)
        {
            string myUrl = baseUrl + "/receiver/send";

            string boundary = "---------------------------" + DateTime.Now.Ticks.ToString("x");
            byte[] boundaryBytes = System.Text.Encoding.ASCII.GetBytes("--" + boundary + "\r\n");

            HttpWebRequest webRequest = (HttpWebRequest)HttpWebRequest.Create(myUrl);

            webRequest.ContentType = "multipart/form-data; boundary=" + boundary;
            webRequest.Method = "POST";
            webRequest.KeepAlive = true;
            webRequest.Credentials = System.Net.CredentialCache.DefaultCredentials;

            Stream rs = webRequest.GetRequestStream();

            // create normal post fields
            if (nvc == null)
            {
                nvc = new NameValueCollection();
            }

            using (Stream requestStream = webRequest.GetRequestStream())
            {
                nvc.Add("instanceid", instanceId); // instance isolation

                foreach (string key in nvc)
                {
                    requestStream.Write(boundaryBytes, 0, boundaryBytes.Length);
                    string requestTemplate = "Content-Disposition: form-data; name=\"{0}\"\r\n\r\n";
                    string requestFormatted = string.Format(requestTemplate, key);
                    byte[] requestBytes = System.Text.Encoding.UTF8.GetBytes(requestFormatted);
                    requestStream.Write(requestBytes, 0, requestBytes.Length);

                    byte[] instanceIdBytes = System.Text.Encoding.UTF8.GetBytes(nvc[key] + "\r\n");
                    requestStream.Write(instanceIdBytes, 0, instanceIdBytes.Length);
                }

                /***** source file upload *****/
                // write boundary bytes
                requestStream.Write(boundaryBytes, 0, boundaryBytes.Length);

                // write header bytes.
                string headerTemplate = "Content-Disposition: form-data; name=\"{0}\"; filename=\"{1}\"\r\nContent-Type: {2}\r\n\r\n";
                string header = string.Format(headerTemplate, "contents", "contents.zip", "appliation/octet-stream");
                byte[] headerbytes = System.Text.Encoding.UTF8.GetBytes(header);
                requestStream.Write(headerbytes, 0, headerbytes.Length);

                using (MemoryStream memoryStream = new MemoryStream(sc))
                {
                    byte[] buffer = new byte[sc.Length];
                    int bytesRead = 0;
                    while ((bytesRead = memoryStream.Read(buffer, 0, buffer.Length)) != 0)
                    {
                        requestStream.Write(buffer, 0, bytesRead);
                    }
                }

                // write trailing boundary bytes.
                byte[] trailerBytes = System.Text.Encoding.ASCII.GetBytes("\r\n--" + boundary + "--\r\n");
                requestStream.Write(trailerBytes, 0, trailerBytes.Length);
            }

            // response
            using (HttpWebResponse wr = (HttpWebResponse)webRequest.GetResponse())
            {
                using (Stream response = wr.GetResponseStream())
                {
                    StreamReader reader = new StreamReader(response);
                    string text = reader.ReadToEnd();

                    Console.WriteLine(text);
                }
            }
        }

        // Make random wait time
        private int makeWaitMs()
        {
            int waitSeconds = 15;
            Random rand = new Random();
            waitSeconds += rand.Next(0, 10);

            return waitSeconds * 1000;
        }

        public void WaitSeconds(int seconds)
        {
            Thread.Sleep(seconds * 1000);
        }

        // bypass robot protection
        private void bypassRobotProtection()
        {
            if(robotProtection == true) {
                int ms = makeWaitMs();
                Console.WriteLine("로봇 감지 프로세스로부터 검색창을 보호합니다. " + (ms / 1000) + "초 대기하여 주세요.");
                Thread.Sleep(ms);
            }
        }

        // Reqeust By HTTP GET
        public string RequestByHttpGet(string url, NameValueCollection nvc = null)
        {
            string connectUrl = url;
            if(nvc != null)
            {
                int nvcCount = 0;
                connectUrl += "?";
                foreach(string key in nvc)
                {
                    if(nvcCount > 0)
                    {
                        connectUrl += "&";
                    }
                    connectUrl += key + "=" + nvc[key];
                    nvcCount++;
                }
            }

            string responseFromServer = "";
            bool retry = true;
            int retryCnt = 0;

            WebRequest request = WebRequest.Create(connectUrl);
            request.Credentials = CredentialCache.DefaultCredentials;

            while(retry) { 
                try
                {
                    WebResponse response = request.GetResponse();
                    //Console.WriteLine(((HttpWebResponse)response).StatusDescription);

                    Stream dataStream = response.GetResponseStream();
                    StreamReader reader = new StreamReader(dataStream);
                    responseFromServer = reader.ReadToEnd();

                    reader.Close();
                    response.Close();

                    retry = false;
                } catch(System.Net.WebException)
                {
                    retryCnt++;
                    Console.WriteLine("작업을 다시 시도합니다. (" + retryCnt + "회 재시도)");
                    WaitSeconds(1);
                }
            }

            return responseFromServer;
        }

        public void Close()
        {
            driver.Close();
        }
    }
}
